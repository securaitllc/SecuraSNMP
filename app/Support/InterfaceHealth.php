<?php

namespace App\Support;

use App\Models\DeviceInterface;
use Carbon\CarbonInterface;

/**
 * Derives a single "health" verdict for an interface from signals the poller
 * already records, so the panel can show WHY a port needs attention (not just
 * up/down) and the operator can act on it. Pure/stateless → unit-testable.
 *
 * An acknowledged condition (health_ack_at) is treated as handled until a NEWER
 * fault of that kind lands (last_*_at > health_ack_at) — a "mark as read" that
 * re-arms itself, so acking never permanently blinds a port.
 */
class InterfaceHealth
{
    // Conservative windows/floors: enough to catch a real fault, high enough not
    // to pill on a stray CRC frame or a single dropped packet.
    public const ERROR_FLOOR = 10;      // new in+out errors within the window
    public const DISCARD_FLOOR = 100;   // new in+out discards within the window
    public const FLAP_RECENT_HOURS = 6; // a status change this recent = flapping

    /**
     * @return array{status: string, attention: bool}
     *   status ∈ admin_down | down | flapping | errors | congested | muted | clean
     *   attention = should the pill draw the eye (unacked & actionable)
     */
    public static function classify(
        DeviceInterface $if,
        int $errorsRecent,
        int $discardsRecent,
        CarbonInterface $now,
    ): array {
        $ackAt = $if->health_ack_at;

        // A fault "counts" unless it's older than an acknowledgement of it.
        $fresh = fn ($at): bool => $at !== null && ($ackAt === null || $at->gt($ackAt));

        // Hard states first — these are about reachability, not counters.
        if ($if->admin_status === 'down') {
            return ['status' => 'admin_down', 'attention' => false];
        }
        if ($if->status === 'down') {
            // A muted (suppressed) down port is intentionally silenced.
            return $if->alarm_suppressed
                ? ['status' => 'muted', 'attention' => false]
                : ['status' => 'down', 'attention' => true];
        }

        $flapping = $if->last_flap_at !== null
            && $if->last_flap_at->gt($now->copy()->subHours(self::FLAP_RECENT_HOURS))
            && $fresh($if->last_flap_at);
        if ($flapping) {
            return ['status' => 'flapping', 'attention' => ! $if->alarm_suppressed];
        }

        if ($errorsRecent >= self::ERROR_FLOOR && $fresh($if->last_error_at)) {
            return ['status' => 'errors', 'attention' => ! $if->alarm_suppressed];
        }

        if ($discardsRecent >= self::DISCARD_FLOOR && $fresh($if->last_discard_at)) {
            return ['status' => 'congested', 'attention' => ! $if->alarm_suppressed];
        }

        return ['status' => $if->alarm_suppressed ? 'muted' : 'clean', 'attention' => false];
    }
}
