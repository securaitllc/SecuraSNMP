<?php

namespace App\Providers;

use App\Listeners\RecordAuthEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Authentication outcomes go to the same audit trail as API writes (LOG-01).
        Event::subscribe(RecordAuthEvent::class);
    }
}
