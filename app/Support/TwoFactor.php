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

    /** Verify a 6-digit TOTP against the secret, allowing ±1 step for clock drift. */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        return (bool) self::google2fa()->verifyKey($secret, $code, 1);
    }

    public static function otpauthUrl(User $user, string $secret): string
    {
        return self::google2fa()->getQRCodeUrl((string) config('mfa.issuer', 'Nodus'), $user->email, $secret);
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
