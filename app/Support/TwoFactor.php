<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Thin wrapper around Google2FA + a self-contained SVG QR renderer (no JS/CDN). */
class TwoFactor
{
    public static function google2fa(): Google2FA
    {
        return new Google2FA();
    }

    public static function generateSecret(): string
    {
        return self::google2fa()->generateSecretKey();
    }

    /**
     * Verify a TOTP for a user with anti-replay: the code's 30s step must be
     * strictly newer than the last one accepted (persisted on the user), so a
     * code captured in transit can't be replayed inside its validity window.
     * ±1 step of drift is allowed.
     */
    public static function verifyForUser(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', (string) $code);

        $ts = self::google2fa()->verifyKeyNewer(
            (string) $user->two_factor_secret,
            $code,
            (int) ($user->two_factor_last_ts ?? 0),
            1,
        );

        if ($ts === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_ts' => $ts])->save();

        return true;
    }

    public static function otpauthUrl(User $user, string $secret): string
    {
        $url = self::google2fa()->getQRCodeUrl((string) config('mfa.issuer', 'Nodus'), $user->email, $secret);

        // Append the non-standard `image` param so authenticators that support it
        // render the Nodus logo. Apps that don't (Google Authenticator) ignore it.
        $logo = (string) config('mfa.logo_url');

        return $logo !== '' ? $url.'&image='.rawurlencode($logo) : $url;
    }

    /** Inline SVG QR for the otpauth URL — safe under a strict CSP (no external calls). */
    public static function qrSvg(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd());

        return (new Writer($renderer))->writeString($otpauthUrl);
    }

    /**
     * Fresh one-time recovery codes (formatted XXXXX-XXXXX). Stored hashed-ish by
     * encryption at rest on the model; matched case-insensitively on use.
     *
     * @return list<string>
     */
    public static function recoveryCodes(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
