<?php

namespace App\Observers;

use App\Services\AlertNotifier;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns any alert model's create/resolve into a notification. Every alert table
 * (circuit/interface/tunnel/next-hop/device-alarm) is a subclass, so wiring an
 * observer is the single choke point that catches all current and future alerts.
 */
abstract class AbstractAlertObserver
{
    /** Column that, once set, marks the alert resolved (ended_at / cleared_at). */
    abstract protected function resolveField(): string;

    /**
     * @return array{severity: string, open_subject: string, resolved_subject: string, body: string, device_id: ?int, site_id: ?int, context: array<string, mixed>}
     */
    abstract protected function describe(Model $alert): array;

    public function created(Model $alert): void
    {
        // Skip alerts created already-resolved (e.g. historical/seed data).
        if ($alert->{$this->resolveField()} !== null) {
            return;
        }

        $d = $this->describe($alert);
        AlertNotifier::dispatch('open', $d['severity'], $d['open_subject'], $d['body'], $d['context'], $d['device_id'], $d['site_id']);
    }

    public function updated(Model $alert): void
    {
        $field = $this->resolveField();

        if ($alert->wasChanged($field) && $alert->{$field} !== null) {
            $d = $this->describe($alert);
            AlertNotifier::dispatch('resolved', $d['severity'], $d['resolved_subject'], $d['body'], $d['context'], $d['device_id'], $d['site_id']);
        }
    }
}
