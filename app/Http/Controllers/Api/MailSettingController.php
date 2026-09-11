<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AlertMail;
use App\Models\MailSetting;
use App\Services\MailConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Manage the singleton SMTP settings and send a test email. The password is
 * never returned; the client only learns whether one is set.
 */
class MailSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->present(MailSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'in:tls,ssl,none'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'from_address' => ['nullable', 'email'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ]);

        $setting = MailSetting::current();
        // Only overwrite the stored password when a new one is supplied — a blank
        // field means "keep the existing password", not "clear it".
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }
        $setting->update($data);

        return response()->json($this->present($setting->fresh()));
    }

    public function test(Request $request): JsonResponse
    {
        $to = $request->validate(['to' => ['required', 'email']])['to'];

        try {
            MailConfigurator::apply();
            Mail::to($to)->send(new AlertMail(
                'open',
                'info',
                'Nodus — SMTP test',
                "Result: SMTP delivery is working.\nThis is a test message sent from the Nodus email settings page.",
                ['type' => 'smtp_test'],
            ));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => "Test email sent to {$to}."]);
    }

    /** @return array<string, mixed> */
    private function present(MailSetting $s): array
    {
        return [
            'host' => $s->host,
            'port' => $s->port,
            'encryption' => $s->encryption,
            'username' => $s->username,
            'has_password' => (bool) $s->password,   // never expose the value
            'from_address' => $s->from_address,
            'from_name' => $s->from_name,
            'enabled' => $s->enabled,
        ];
    }
}
