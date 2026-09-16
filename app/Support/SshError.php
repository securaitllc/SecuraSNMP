<?php

namespace App\Support;

/**
 * Maps a raw SSH exception message to a safe, actionable reason. Raw messages
 * are never surfaced to the client because they can carry secrets (a password
 * echoed by the SSH library, etc.).
 */
class SshError
{
    public static function safe(string $raw): string
    {
        $lower = strtolower($raw);

        return match (true) {
            str_contains($lower, 'login failed') || str_contains($lower, 'authentication') =>
                'SSH login failed — check the SSH username and password.',
            str_contains($lower, 'command') =>
                'Connected, but a command failed on the device.',
            default =>
                'Could not establish an SSH session — check that the device is reachable and SSH (port 22) is open.',
        };
    }
}
