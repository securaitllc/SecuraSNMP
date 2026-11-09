<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitPathProbe;
use Symfony\Component\Process\Process;

/**
 * Runs a hop-by-hop probe toward a circuit's monitored address and records it.
 *
 * mtr when the image has it, traceroute otherwise. Both are parsed into the same hop
 * shape, so everything downstream reads one thing.
 *
 * THE ONE JUDGEMENT THIS MAKES, and the reason a raw trace is not enough on its own: a
 * hop that drops probes while every hop after it is clean is not losing traffic. Core
 * routers rate-limit ICMP replies to themselves and forward everything else at line
 * rate — that hop reading 60% loss is the router declining to answer, not the path
 * failing. Real loss shows up at a hop AND persists through every hop beyond it,
 * because those probes never came back either. worstHop() is the first hop where that
 * holds, which on #037 is what pointed at the Level3 aggregate rather than at the
 * carrier's edge that merely refuses to be pinged.
 */
class CircuitPathProber
{
    /**
     * Probes per hop.
     *
     * Resolution is 100/CYCLES: ten cycles cannot tell 5% from 0% or 15% from 10%, and
     * a carrier will argue a 10% reading is one dropped packet. Twenty cycles reads to
     * 5%, which is the floor at which a hop's loss stops being deniable.
     *
     * Cost is not what it looks like. mtr sends every cycle to every hop concurrently,
     * so runtime is cycles × interval — about six seconds at 0.3s — plus the timeout on
     * hops that never answer. Doubling the cycles adds seconds, not minutes. The
     * traceroute fallback IS sequential and slower, which is one more reason the image
     * ships mtr.
     */
    private const CYCLES = 20;

    /** traceroute's -q. Sequential per hop, so this stays small. */
    private const TRACEROUTE_PROBES = 3;

    /**
     * How far a later hop may improve on an earlier one before the earlier hop's loss
     * is judged not to persist. Slack for jitter between concurrent probe streams.
     */
    private const PERSIST_TOLERANCE = 15;

    /**
     * @param  (callable(array<int,string>): string)|null  $runner  Override for tests:
     *                                                              takes the command argv, returns stdout. Production shells out.
     * @param  string|null  $tool  Force 'mtr' or 'traceroute'. Tests pin it so a fixture
     *                             parses the same on a host with mtr and one without; production detects.
     */
    public function __construct(private $runner = null, private ?string $tool = null) {}

    /**
     * Probe the path and record it. Null when the circuit has nothing to probe or the
     * tool produced nothing usable — a failed probe is not an empty path.
     */
    public function probe(Circuit $circuit, string $trigger = 'manual', ?string $ranBy = null): ?CircuitPathProbe
    {
        $target = $circuit->monitored_ip ?: $circuit->gateway_ip;
        if (! $target || ! filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        [$tool, $argv] = self::command($target, $this->tool);
        $output = $this->runner ? ($this->runner)($argv) : self::run($argv);
        $hops = $tool === 'mtr' ? self::parseMtr($output) : self::parseTraceroute($output);

        if ($hops === []) {
            return null;
        }

        $worst = self::worstHop($hops);
        $end = end($hops);

        return CircuitPathProbe::create([
            'circuit_id' => $circuit->id,
            'target' => $target,
            'trigger' => $trigger,
            'tool' => $tool,
            // What was actually sent per hop. traceroute's fallback is three probes, and
            // stamping twenty on it would claim a precision the reading does not have.
            'cycles' => $tool === 'mtr' ? self::CYCLES : self::TRACEROUTE_PROBES,
            'hops' => $hops,
            'worst_hop' => $worst['hop'] ?? null,
            'worst_hop_host' => $worst['host'] ?? $worst['ip'] ?? null,
            'worst_hop_loss_pct' => $worst['loss_pct'] ?? null,
            'end_loss_pct' => $end['loss_pct'] ?? null,
            'summary' => self::summarise($hops, $worst),
            'ran_by' => $ranBy,
            'ran_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: array<int, string>} tool name, argv */
    public static function command(string $target, ?string $force = null): array
    {
        if ($force === 'mtr' || ($force === null && self::hasMtr())) {
            // --report-wide keeps hostnames untruncated; -n off so the carrier hop is
            // NAMED (ae31-527.bar3.orlando1.level3.net), which is what gets quoted.
            return ['mtr', ['mtr', '--report-wide', '-c', (string) self::CYCLES, '-w', '-i', '0.3', $target]];
        }

        return ['traceroute', ['traceroute', '-q', (string) self::TRACEROUTE_PROBES, '-w', '2', '-m', '20', $target]];
    }

    public static function hasMtr(): bool
    {
        static $has = null;

        return $has ??= (bool) trim((string) shell_exec('command -v mtr 2>/dev/null'));
    }

    private static function run(array $argv): string
    {
        $p = new Process($argv);
        $p->setTimeout(120);
        $p->run();

        return $p->getOutput();
    }

    /**
     * mtr --report-wide:
     *
     *   HOST: nodus                       Loss%   Snt   Last   Avg  Best  Wrst StDev
     *     1.|-- 10.200.24.254              0.0%    10    0.4   0.5   0.3   0.9   0.2
     *     5.|-- ae31-527.bar3.orlando1.level3.net  40.0%  10  12.1  13.0  11.8  18.4  2.1
     *    12.|-- ???                       100.0    10    0.0   0.0   0.0   0.0   0.0
     *
     * @return array<int, array{hop:int, host:?string, ip:?string, sent:int, loss_pct:int, avg_ms:?float, best_ms:?float, worst_ms:?float}>
     */
    public static function parseMtr(string $output): array
    {
        $hops = [];
        foreach (explode("\n", $output) as $line) {
            if (! preg_match('/^\s*(\d+)\.\|--\s+(\S+)\s+([\d.]+)%?\s+(\d+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $line, $m)) {
                continue;
            }
            $host = $m[2] === '???' ? null : $m[2];
            $isIp = $host !== null && filter_var($host, FILTER_VALIDATE_IP);
            $hops[] = [
                'hop' => (int) $m[1],
                'host' => $isIp ? null : $host,
                'ip' => $isIp ? $host : null,
                'sent' => (int) $m[4],
                'loss_pct' => (int) round((float) $m[3]),
                'avg_ms' => $host === null ? null : (float) $m[6],
                'best_ms' => $host === null ? null : (float) $m[7],
                'worst_ms' => $host === null ? null : (float) $m[8],
            ];
        }

        return $hops;
    }

    /**
     * traceroute -q 3:
     *
     *    5  ae31-527.bar3.orlando1.level3.net (4.69.150.10)  12.1 ms  * 13.4 ms
     *   12  * * *
     *
     * Loss is stars over probes. Coarse, but a 3-probe hop reading 33% or 67% still
     * says which hop.
     *
     * @return array<int, array{hop:int, host:?string, ip:?string, sent:int, loss_pct:int, avg_ms:?float, best_ms:?float, worst_ms:?float}>
     */
    public static function parseTraceroute(string $output): array
    {
        $hops = [];
        foreach (explode("\n", $output) as $line) {
            if (! preg_match('/^\s*(\d+)\s+(.*)$/', $line, $m)) {
                continue;
            }
            $rest = trim($m[2]);
            $stars = substr_count($rest, '*');
            preg_match_all('/([\d.]+)\s*ms/', $rest, $times);
            $rtts = array_map('floatval', $times[1]);
            $sent = count($rtts) + $stars;
            if ($sent === 0) {
                continue;
            }

            $host = null;
            $ip = null;
            if (preg_match('/^([^\s(]+)\s+\(([\d.]+)\)/', $rest, $h)) {
                $host = $h[1] === $h[2] ? null : $h[1];
                $ip = $h[2];
            } elseif (preg_match('/^([\d.]+)\s/', $rest, $h)) {
                $ip = $h[1];
            }

            $hops[] = [
                'hop' => (int) $m[1],
                'host' => $host,
                'ip' => $ip,
                'sent' => $sent,
                'loss_pct' => (int) round($stars / $sent * 100),
                'avg_ms' => $rtts ? round(array_sum($rtts) / count($rtts), 1) : null,
                'best_ms' => $rtts ? min($rtts) : null,
                'worst_ms' => $rtts ? max($rtts) : null,
            ];
        }

        return $hops;
    }

    /**
     * The first hop whose loss PERSISTS to the end of the path — see the class doc.
     *
     * A hop only counts when every hop after it also shows loss at least a few points
     * below its own; a hop at 60% followed by hops at 0% is a router declining to
     * answer ICMP, and naming it would send the carrier chasing the wrong box.
     *
     * @param  array<int, array<string, mixed>>  $hops
     * @return array<string, mixed>|null
     */
    /**
     * Mark each hop as forwarding traffic badly, or merely refusing to talk about itself.
     *
     * A router deprioritises ICMP addressed TO its own control plane — Linux ships
     * net.ipv4.icmp_ratelimit at one message per second, and carrier routers police
     * harder. So a hop can report 45% while every probe still reaches the destination
     * behind it. The verdict line already understood this; the hop table did not, and
     * painted those percentages amber next to a headline saying the path was clean.
     * An operator reading the scary number over the correct headline is a bug.
     *
     * A hop's loss is REAL only if it persists to the end of the path — the same rule
     * worstHop() applies, kept here so both answers can never disagree.
     *
     * @param  array<int, array<string, mixed>>  $hops
     * @return array<int, array<string, mixed>> each with rate_limited: bool
     */
    public static function classifyHops(array $hops): array
    {
        $n = count($hops);
        for ($i = 0; $i < $n; $i++) {
            $loss = (int) ($hops[$i]['loss_pct'] ?? 0);
            if ($loss <= 0) {
                $hops[$i]['rate_limited'] = false;

                continue;
            }
            $persists = true;
            for ($j = $i + 1; $j < $n; $j++) {
                if ((int) ($hops[$j]['loss_pct'] ?? 0) < $loss - self::PERSIST_TOLERANCE) {
                    $persists = false;
                    break;
                }
            }
            // The last hop IS the destination: nothing follows it to corroborate, and
            // loss there is loss. Never excused.
            $hops[$i]['rate_limited'] = ! $persists && $i < $n - 1;
        }

        return $hops;
    }

    public static function worstHop(array $hops): ?array
    {
        $n = count($hops);
        for ($i = 0; $i < $n; $i++) {
            $loss = (int) $hops[$i]['loss_pct'];
            if ($loss <= 0) {
                continue;
            }
            $persists = true;
            for ($j = $i + 1; $j < $n; $j++) {
                if ((int) $hops[$j]['loss_pct'] < $loss - self::PERSIST_TOLERANCE) {
                    $persists = false;
                    break;
                }
            }
            if ($persists) {
                return $hops[$i];
            }
        }

        return null;
    }

    /** @param  array<int, array<string, mixed>>  $hops */
    private static function summarise(array $hops, ?array $worst): string
    {
        $end = end($hops);
        $endLoss = (int) ($end['loss_pct'] ?? 0);

        // Nothing answered from some hop onward: that is not loss at a hop, it is the
        // path going dark. Name the last hop that DID answer — that is where the
        // carrier starts looking — rather than a numbered hop with no name.
        if ($endLoss >= 100 && $worst !== null && (int) $worst['loss_pct'] >= 100) {
            $last = null;
            foreach ($hops as $h) {
                if ((int) $h['loss_pct'] < 100) {
                    $last = $h;
                }
            }
            $lastName = $last ? ($last['host'] ?? $last['ip'] ?? "hop {$last['hop']}") : 'the collector';

            return "No reply beyond hop {$worst['hop']} — last hop to answer was {$lastName}; the target is unreachable from there";
        }

        if ($worst === null) {
            return $endLoss > 0
                ? "{$endLoss}% loss at the target with no single hop responsible — likely the last mile"
                : 'Clean path — no persistent loss at any hop';
        }

        $name = $worst['host'] ?? $worst['ip'] ?? "hop {$worst['hop']} (no name)";

        return "Loss starts at hop {$worst['hop']} ({$name}) at {$worst['loss_pct']}% and persists to the target ({$endLoss}%)";
    }
}
