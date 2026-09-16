<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Provenance for anything this app reports about the network.
 *
 * THE RULE: absence of a signal is never a signal. A device that stops answering
 * does not become healthy, and it does not become broken either — it becomes
 * UNKNOWN, and the screen has to say so.
 *
 * This is the single most expensive bug class in this codebase. Every one of these
 * was the same mistake in a different file:
 *
 *   - a site with both circuits down and its appliance dark read "running on backup
 *     WAN" off a frozen SSH tunnel table;
 *   - the node panel showed "AZURE 5 up · FL0001-HQ 10 up" with CPU and memory bars
 *     directly beneath the words "appliance unreachable";
 *   - hovering the LAN link to that appliance reported "Reachable", because the edge
 *     was built with the literal string 'up';
 *   - IPAM attributed 121 addresses to a switch port that was down with an empty
 *     forwarding table;
 *   - a range read "almost full" because addresses nothing could identify were
 *     spent out of the free count.
 *
 * They were fixed one at a time, each with its own ad-hoc flag — a `measured`
 * boolean here, an `'unknown'` state string there, a `tunnels_stale` elsewhere.
 * Three vocabularies for one idea is how the fourth instance gets written.
 *
 * So: one vocabulary. Stamp a payload with where it came from and whether that
 * source actually confirmed it, and let the UI render "not measured" the same way
 * everywhere instead of each view inventing its own.
 */
final class Measurement
{
    /** Polled over SNMP — the authoritative, real-time signal. */
    public const SNMP = 'snmp';

    /** Read over SSH. The fleet sweep takes minutes, so these tables go stale. */
    public const SSH = 'ssh';

    /** ICMP reachability. */
    public const ICMP = 'icmp';

    /** Written down by a person, or derived from what was. Not observed at all. */
    public const RECORDED = 'recorded';

    /** Inferred from topology rather than probed — nothing measures a cable. */
    public const INFERRED = 'inferred';

    /**
     * Stamp a payload with its provenance.
     *
     * The payload keeps its own shape — this only adds the four provenance keys, so
     * it can be applied to an existing response without breaking its readers.
     *
     * @param  array<string, mixed>  $payload
     * @param  bool  $measured  did the source actually confirm this, now?
     * @param  string  $source  one of the constants above
     * @param  string|null  $why  plain words for the screen when it did not: this is
     *                            shown to an operator, so write it for them
     * @return array<string, mixed>
     */
    public static function stamp(array $payload, bool $measured, string $source, ?Carbon $at = null, ?string $why = null): array
    {
        return $payload + [
            'measured' => $measured,
            'source' => $source,
            'as_of' => $at?->toIso8601String(),
            // Only carried when it is missing — an operator asking "why is this grey"
            // should not have to guess, and "unknown" on its own does not answer it.
            'why' => $measured ? null : ($why ?? 'not measured'),
        ];
    }

    /**
     * The state to report for something that may not have been measured.
     *
     * Never let an unmeasured thing borrow 'up' or 'down'. Colour is severity in
     * this app, so either one is a claim; 'unknown' is the honest third answer and
     * renders neutral.
     */
    public static function state(string $stateIfMeasured, bool $measured): string
    {
        return $measured ? $stateIfMeasured : 'unknown';
    }
}
