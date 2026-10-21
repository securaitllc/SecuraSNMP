<?php

namespace App\Console\Commands;

use App\Models\Circuit;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Stamp each site's DIA circuit with the date its transport was cut over in 2024.
 *
 * The dates came from the carrier's cutover tracker, which is keyed by service-centre
 * number and street address rather than by anything in this system. Correlating it was
 * the real work: a single row often covers several co-located service centres
 * ("SC 25/50/106"), only the first of which is usually monitored here, and the dates
 * arrive in half a dozen shapes ("22-Apr", "Completed 3/26", "7/10 12pm CT Complete",
 * reschedule chains, "NA", "CANCELLED").
 *
 * The service-centre NUMBER is the key, not the address. Several sites have moved
 * since the cutover, so this system holds their current address while the tracker
 * holds the 2024 one — an address that disagrees is usually the move itself, not a
 * bad match. The correlation is worth one sanity check: #887 resolves to exactly two
 * DIA circuits, which is what the source list says that site has.
 *
 * SC 189 is why the number has to win. It cut over on 3/26 as SC 730 at 2609 Phyllis
 * St, Jacksonville, was renumbered to 189, and has since moved to Knoxville — while
 * SC 106 later moved INTO that same Jacksonville building. Two relocations crossing
 * at one address makes the source list look self-contradictory; matching on the
 * number resolves it, and 189 and 106 keep their own separate cutover dates.
 *
 * The DIA contracts run three years from the cutover, so the term and the expiry
 * date are derived from it here rather than typed in 97 times. That is what feeds
 * the contract-expiry reporting: these cutovers land in 2024, so the renewals fall
 * due across 2027.
 *
 * Dry-run by default. Only ever fills an EMPTY install_date.
 */
class ImportDiaCutover extends Command
{
    protected $signature = 'circuits:import-dia-cutover
        {--apply : Write the dates. Without this the command only reports what it would do}
        {--term-months=36 : DIA contract length — the term runs from the cutover date}
        {--force : Also overwrite an install_date that is already set}';

    protected $description = 'Set the DIA transport cutover date on each site\'s fiber circuit.';

    /** site_number => cutover date (2024). Verified against site number AND street number. */
    private const CUTOVER = [
        '003' => '2024-03-26',   // #003 Winter Garden FL
        '005' => '2024-04-23',   // #005 Ocala FL
        '006' => '2024-07-08',   // #006 Orange Park FL
        '007' => '2024-03-26',   // #007 Daytona FL
        '008' => '2024-04-02',   // #008 Port Orange FL
        '009' => '2024-07-11',   // #009 Port St Lucie FL
        '010' => '2024-03-15',   // #010 Oldsmar FL
        '011' => '2024-04-23',   // #011 Leesburg FL
        '014' => '2024-03-26',   // #014 Lake Mary FL
        '015' => '2024-03-26',   // #015 Kissimmee FL  (2776 -> 2786 Michigan Ave)
        '019' => '2024-03-26',   // #019 Oviedo FL
        '022' => '2024-03-14',   // #022 Deland FL
        '023' => '2024-03-26',   // #023 Melbourne FL
        '024' => '2024-07-11',   // #024 Boca Commercial FL
        '025' => '2024-07-17',   // #025 Jacksonville FL
        '031' => '2024-05-06',   // #031 The Villages North FL
        '035' => '2024-07-09',   // #035 Fayetteville GA
        '036' => '2024-03-26',   // #036 Southeast Orlando FL
        '037' => '2024-05-22',   // #037 Lawrenceville GA
        '039' => '2024-03-13',   // #039 Clearwater FL
        '040' => '2024-07-31',   // #040 North Jacksonville FL
        '041' => '2024-03-13',   // #041 GU Clermont FL
        '042' => '2024-06-20',   // #042 Brooksville FL
        '044' => '2024-05-21',   // #044 Alpharetta GA
        '045' => '2024-04-22',   // #045 Fort Myers Commercial FL
        '048' => '2024-04-04',   // #048 Palm Coast FL
        '049' => '2024-03-27',   // #049 Apopka FL
        '051' => '2024-04-24',   // #051 The Villages New Construction FL
        '052' => '2024-04-23',   // #052 North Tallahassee FL
        '055' => '2024-04-04',   // #055 West Palm Beach FL
        '056' => '2024-03-14',   // #056 Winter Haven FL
        '057' => '2024-04-25',   // #057 South Tallahassee FL
        '058' => '2024-07-17',   // #058 Ponte Vedra FL
        '059' => '2024-06-19',   // #059 Gainesville FL
        '061' => '2024-04-01',   // #061 Vero Beach FL
        '062' => '2024-06-25',   // #062 Sarasota FL
        '063' => '2024-05-08',   // #063 Baton Rouge LA
        '064' => '2024-03-13',   // #064 Eustis FL
        '065' => '2024-04-04',   // #065 New Smyrna FL
        '066' => '2024-04-24',   // #066 GU Villages Central FL
        '068' => '2024-07-10',   // #068 Bradenton FL
        '070' => '2024-06-18',   // #070 St Augustine FL
        '075' => '2024-03-12',   // #075 GU Lakeland FL
        '085' => '2024-03-26',   // #085 Avalon Park FL  (moved from 2504 S Alafaya Trl)
        '086' => '2024-03-26',   // #086 Lake Nona FL
        '087' => '2024-03-26',   // #087 Longwood FL
        '088' => '2024-03-26',   // #088 Windermere FL
        '089' => '2024-04-02',   // #089 GU Ormond Beach FL
        '093' => '2024-04-03',   // #093 New Tampa FL
        '097' => '2024-07-30',   // #097 Euless Residential TX
        '098' => '2024-06-27',   // #098 Dallas Commercial TX
        '101' => '2024-07-11',   // #101 San Antonio Commercial TX
        '102' => '2024-11-28',   // #102 Jacksonville Beach FL
        '104' => '2024-06-27',   // #104 Fort Worth Commercial TX
        '105' => '2024-07-17',   // #105 Plano Residential TX
        '106' => '2024-07-17',   // #106 Jacksonville Commercial FL  (moved from 11283 Old Saint Augustine Rd)
        '107' => '2024-07-10',   // #107 GU Palm Harbor FL
        '114' => '2024-04-22',   // #114 Fort Myers Residential FL
        '121' => '2024-03-26',   // #121 West Osceola FL
        '122' => '2024-05-22',   // #122 Leander TX
        '123' => '2024-05-21',   // #123 Dallas GA
        '124' => '2024-05-06',   // #124 Sandy Springs GA
        '125' => '2024-07-09',   // #125 Winder GA
        '126' => '2024-04-26',   // #126 Brandon FL
        '128' => '2024-04-24',   // #128 Suwanee GA
        '131' => '2024-04-22',   // #131 Greenville SC
        '132' => '2024-04-02',   // #132 Columbia SC
        '133' => '2024-06-25',   // #133 Charleston SC
        '134' => '2024-06-19',   // #134 Canton GA
        '135' => '2024-07-30',   // #135 Cumming GA
        '138' => '2024-07-11',   // #138 Edmond OK
        '146' => '2024-07-10',   // #146 East Houston TX
        '147' => '2024-06-11',   // #147 The Woodlands TX
        '150' => '2024-06-25',   // #150 Austin (Pflugerville) TX
        '151' => '2024-05-06',   // #151 Moore OK
        '154' => '2024-06-27',   // #154 Raleigh, NC
        '155' => '2024-05-29',   // #155 Charlotte, NC
        '162' => '2024-07-31',   // #162 Gainesville GA
        '163' => '2024-06-25',   // #163 Fuquay NC
        '164' => '2024-04-22',   // #164 Decatur GA
        '165' => '2024-07-08',   // #165 GU Orange Park FL  (moved from 2440 Lucy Branch Rd)
        '166' => '2024-07-11',   // #166 Hilton Head SC
        '167' => '2024-04-23',   // #167 Villages South FL
        '170' => '2024-07-10',   // #170 Lake Norman NC
        '171' => '2024-07-17',   // #171 NW San Antonio TX
        '172' => '2024-07-10',   // #172 GU St Petersburg FL
        '174' => '2024-04-01',   // #174 Denton TX
        '175' => '2024-07-17',   // #175 McKinney TX
        '177' => '2024-04-04',   // #177 Cocoa Commercial FL
        '179' => '2024-04-03',   // #179 Doral FL
        '181' => '2024-03-12',   // #181 Wilmington NC
        '182' => '2024-03-13',   // #182 Virginia Beach VA
        '184' => '2024-05-21',   // #184 Chattanooga TN
        '185' => '2024-06-26',   // #185 Cleveland TN
        '189' => '2024-03-26',   // #189 Knoxville TN  (cut over as SC 730 at 2609 Phyllis St, Jacksonville)
        '193' => '2024-03-11',   // #193 Johnson City TN
        '196' => '2024-02-26',   // #196 Aiken (Aiken County) SC
        '887' => '2024-04-22',   // #887 Customer Care FL
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $term = (int) $this->option('term-months');

        if ($term < 1) {
            $this->error('Term must be a positive number of months.');

            return self::FAILURE;
        }

        $sites = Site::whereIn('site_number', array_keys(self::CUTOVER))->get()->keyBy('site_number');

        $rows = [];
        $written = 0;
        $missingSite = 0;
        $noCircuit = 0;

        foreach (self::CUTOVER as $number => $date) {
            $site = $sites->get($number);
            if (! $site) {
                $rows[] = [$number, '—', $date, '—', 'site not in this system'];
                $missingSite++;
                continue;
            }

            $circuits = Circuit::where('site_id', $site->id)->where('circuit_type', 'fiber')->get();
            if ($circuits->isEmpty()) {
                $rows[] = [$number, $site->name, $date, '—', 'no DIA circuit at this site'];
                $noCircuit++;
                continue;
            }

            foreach ($circuits as $c) {
                $existing = $c->install_date?->toDateString();
                if ($existing === $date) {
                    $rows[] = [$number, $c->circuit_id, $date, $c->contract_end_date?->toDateString() ?? '—', 'already set'];
                } elseif ($existing !== null && ! $force) {
                    $rows[] = [$number, $c->circuit_id, $date, '—', "kept existing {$existing}"];
                } else {
                    // The contract runs from the cutover, so the expiry is derived rather
                    // than entered by hand — but an expiry somebody already recorded is
                    // real contractual information and outranks anything computed here.
                    $ends = Carbon::parse($date)->addMonths($term)->toDateString();
                    $keepEnd = $c->contract_end_date !== null && ! $force;

                    $rows[] = [$number, $c->circuit_id, $date,
                        $keepEnd ? $c->contract_end_date->toDateString().' (kept)' : $ends,
                        $apply ? 'set' : 'would set'];
                    $written++;

                    if ($apply) {
                        $c->install_date = $date;
                        $c->contract_term_months = $c->contract_term_months !== null && ! $force
                            ? $c->contract_term_months
                            : $term;
                        if (! $keepEnd) {
                            $c->contract_end_date = $ends;
                        }
                        $c->save();
                    }
                }
            }
        }

        $this->table(['Site', 'Circuit', 'Cutover', 'Contract ends', 'Action'], $rows);

        $verb = $apply ? 'Set' : 'Would set';
        $this->info("{$verb} {$written} DIA cutover date(s) across ".count(self::CUTOVER)." site(s), each on a {$term}-month term.");

        if ($missingSite > 0) {
            $this->warn("{$missingSite} site number(s) from the list are not in this system.");
        }
        if ($noCircuit > 0) {
            $this->warn("{$noCircuit} matched site(s) have no fiber circuit to stamp.");
        }
        if (! $apply) {
            $this->comment('Dry run — nothing was written. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }
}
