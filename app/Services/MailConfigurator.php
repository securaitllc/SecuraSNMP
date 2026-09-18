<?php

namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * Applies the UI-managed SMTP settings over Laravel's mail config at runtime, so
 * a configuration change takes effect without touching the container env or
 * clearing the config cache. A no-op when SMTP is not enabled in the UI (the
 * env MAIL_* config then stands).
 */
class MailConfigurator
{
    public static function apply(?MailSetting $setting = null): void
    {
        $setting ??= MailSetting::current();

        if (! $setting->enabled || ! $setting->host) {
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $setting->host);
        Config::set('mail.mailers.smtp.port', $setting->port);
        // Laravel 11+ maps 'tls'/'ssl' via the scheme; 'none' leaves it unset.
        Config::set('mail.mailers.smtp.scheme', match ($setting->encryption) {
            'tls' => 'smtp',      // STARTTLS on 587
            'ssl' => 'smtps',     // implicit TLS on 465
            default => null,
        });
        Config::set('mail.mailers.smtp.username', $setting->username);
        Config::set('mail.mailers.smtp.password', $setting->password);

        if ($setting->from_address) {
            Config::set('mail.from.address', $setting->from_address);
            Config::set('mail.from.name', $setting->from_name ?: 'Nodus Alerts');
        }

        // Drop any mailer built from the old config so the next send rebuilds it.
        Mail::forgetMailers();
    }
}
