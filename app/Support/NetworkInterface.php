<?php

namespace App\Support;

/**
 * Which interface names are the box talking to itself.
 *
 * A control-plane interface is not a place. Nothing plugs into it, no address on it is
 * allocatable, and reporting one as though it were either invites exactly the wrong
 * conclusion: IPAM showed 192.168.1.2 as "assigned — FL0001-HQSWC04 · switch ·
 * em2.32768", which reads as a host on a switch port. em2 is the internal bridge on a
 * Juniper chassis and .32768 is its internal unit; that address exists on every one of
 * them, by default, and means nothing about the site's addressing.
 *
 * Same family as bme0 carrying 128.0.0.1/2 on 167 boxes, which this codebase already
 * excluded — by ADDRESS. Filtering on the address only catches the blocks we happen to
 * know; filtering on the interface catches the reason.
 *
 * FLEET-SPECIFIC, and deliberately so: the managed estate is Silver Peak EdgeConnect,
 * Juniper EX and FortiGate. On Juniper EX, em0/em1/em2 are internal — the Virtual
 * Chassis and control-plane bridges — which is why bare `em<n>` is listed. On some
 * other Juniper platforms em0 is a real out-of-band port, so this list would need
 * revisiting before it were pointed at one.
 *
 * NOT listed, on purpose: me0, vme0 and fxp0 are real out-of-band management ports, and
 * lo0 carries a real router-id address. They are legitimate addressing and belong in an
 * IPAM. Only the units that are internal by construction (lo0.16385, .32768) are.
 */
final class NetworkInterface
{
    /**
     * Interfaces that exist only for the device's own plumbing.
     *
     * @var array<int, string>
     */
    private const CONTROL_PLANE = [
        '/^bme\d/i',            // Juniper internal chassis ethernet
        '/^em\d/i',             // EX Virtual Chassis / control-plane bridges
        '/\.32768$/',           // Juniper internal unit, whatever carries it
        '/^lo\d+\.1638[45]$/i', // the internal loopback units, not lo0 itself
        '/^pfe-/i',             // packet forwarding engine
        '/^pfh-/i',             // host path
        '/^jsrv/i',             // junos services virtual
        '/^(dsc|mtun|pimd|pime|esi|vtep|lsi|tap|gre|ipip|pp0|demux0)\b/i',
        '/^cbp\d/i',            // customer backbone port
        '/^pip\d/i',            // provider instance port
        '/^mt-/i',              // multicast tunnel
    ];

    /**
     * A logical unit of a physical port rather than a port in its own right.
     *
     * Junos reports ge-0/0/11 AND ge-0/0/11.0; Cisco reports Gi0/1 and Gi0/1.100.
     * They are one cable. Counting both doubled every "interfaces down" figure in the
     * app — site #196 read four when one physical port was at fault, and the count
     * was drawn in red beside the alarm chips, so it read as four alarms.
     *
     * A dot always denotes a logical unit on the platforms this polls, so the rule is
     * simply: a name with a dot belongs to the interface before the dot.
     */
    public static function isLogicalUnit(?string $ifName): bool
    {
        return $ifName !== null && str_contains($ifName, '.');
    }

    /** Is this the box talking to itself rather than a port anything reaches? */
    public static function isControlPlane(?string $ifName): bool
    {
        $name = trim((string) $ifName);
        if ($name === '') {
            return false;
        }

        foreach (self::CONTROL_PLANE as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
