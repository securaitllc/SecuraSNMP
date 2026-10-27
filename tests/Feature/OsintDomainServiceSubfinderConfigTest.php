<?php

namespace Tests\Feature;

use App\Services\Osint\OsintDomainService;
use Tests\TestCase;

/**
 * MISC-04 / CLIENT-01: subfinder dropped its config + provider-key files into
 * public/subfinder (its cwd under the web root), where nginx served them to anyone.
 * Any provider API key added there would have been world-readable.
 */
class OsintDomainServiceSubfinderConfigTest extends TestCase
{
    /** MISC-04: nothing under the web root. */
    public function test_no_subfinder_config_is_served_from_public(): void
    {
        $this->assertDirectoryDoesNotExist(public_path('subfinder'));
    }

    /** MISC-04: subfinder is pointed at storage/, and the files exist before it runs. */
    public function test_subfinder_reads_its_config_from_storage(): void
    {
        $cmd = OsintDomainService::subfinderCommand('example.com');

        $config = storage_path('app/subfinder/config.yaml');
        $providers = storage_path('app/subfinder/provider-config.yaml');

        $this->assertSame($config, $cmd[array_search('-config', $cmd, true) + 1]);
        $this->assertSame($providers, $cmd[array_search('-pc', $cmd, true) + 1]);
        $this->assertFileExists($config);
        $this->assertFileExists($providers);
        $this->assertStringNotContainsString(public_path(), implode(' ', $cmd));
    }

    /** MISC-04: an operator's keys are never overwritten by the defaults. */
    public function test_existing_provider_keys_are_left_alone(): void
    {
        $providers = storage_path('app/subfinder/provider-config.yaml');
        OsintDomainService::subfinderCommand('example.com');
        $original = file_get_contents($providers);
        file_put_contents($providers, "shodan: [KEY]\n");

        try {
            OsintDomainService::subfinderCommand('example.com');
            $this->assertSame("shodan: [KEY]\n", file_get_contents($providers));
        } finally {
            file_put_contents($providers, $original);
        }
    }
}
