<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function otp(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }

    public function test_user_enrolls_and_confirms_two_factor(): void
    {
        $user = User::factory()->create();

        $enroll = $this->actingAs($user)->postJson('/api/2fa/enroll');
        $enroll->assertOk()->assertJsonStructure(['secret', 'otpauth_url', 'qr_svg']);
        $secret = $enroll->json('secret');

        $confirm = $this->actingAs($user)->postJson('/api/2fa/confirm', ['code' => $this->otp($secret)]);
        $confirm->assertOk()->assertJsonStructure(['recovery_codes']);
        $this->assertCount(10, $confirm->json('recovery_codes'));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirm_rejects_a_bad_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/2fa/enroll');

        $this->actingAs($user)->postJson('/api/2fa/confirm', ['code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_login_challenges_an_enrolled_user_for_a_code(): void
    {
        $secret = TwoFactor::generateSecret();
        $user = User::factory()->create([
            'password' => Hash::make('Sup3rSecret-Pass'),
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        // Right password, no code → not logged in, prompted for the second factor.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Sup3rSecret-Pass'])
            ->assertOk()->assertJsonPath('two_factor_required', true);

        // Wrong code rejected.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Sup3rSecret-Pass', 'code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        // Valid TOTP logs in.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Sup3rSecret-Pass', 'code' => $this->otp($secret)])
            ->assertOk()->assertJsonPath('user.email', $user->email);
    }

    public function test_recovery_code_logs_in_and_is_consumed(): void
    {
        $secret = TwoFactor::generateSecret();
        $user = User::factory()->create([
            'password' => Hash::make('Sup3rSecret-Pass'),
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['AAAAA-BBBBB', 'CCCCC-DDDDD'],
        ]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Sup3rSecret-Pass', 'code' => 'aaaaa-bbbbb'])
            ->assertOk()->assertJsonPath('user.email', $user->email);

        // The used code is gone; the other remains.
        $this->assertSame(['CCCCC-DDDDD'], array_values($user->fresh()->two_factor_recovery_codes));
    }

    public function test_enforcement_blocks_unenrolled_user_but_allows_enrolment(): void
    {
        config(['mfa.enforced' => true]);
        $user = User::factory()->create();
        Device::factory()->create();

        // A normal protected route is blocked until enrolment.
        $this->actingAs($user)->getJson('/api/devices')
            ->assertStatus(403)->assertJsonPath('mfa_setup_required', true);

        // The enrolment endpoints stay reachable.
        $this->actingAs($user)->postJson('/api/2fa/enroll')->assertOk();
        $this->actingAs($user)->getJson('/api/user')->assertOk();
    }

    public function test_enforcement_off_lets_unenrolled_user_through(): void
    {
        config(['mfa.enforced' => false]);
        $user = User::factory()->create();
        Device::factory()->create();

        $this->actingAs($user)->getJson('/api/devices')->assertOk();
    }

    public function test_reset_command_clears_enrolment(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => TwoFactor::generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->artisan('2fa:reset', ['email' => $user->email])->assertSuccessful();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }
}
