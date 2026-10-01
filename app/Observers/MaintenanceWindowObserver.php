<?php

namespace App\Observers;

use App\Models\MaintenanceWindow;
use App\Support\Maintenance;

/**
 * Drop the memoised window lookup whenever a window changes.
 *
 * App\Support\Maintenance caches its answer for 30 seconds so a dashboard build that
 * walks thousands of rows asks once. That is the right trade for reads, and the wrong
 * one for the moment somebody opens a window and reloads the page expecting the alarm
 * to go quiet: the memo would still be answering from before they clicked.
 *
 * Hung on the model rather than on the two controllers that write windows today, so a
 * third writer — a console command, an import, a future scheduler — cannot forget.
 */
class MaintenanceWindowObserver
{
    public function saved(MaintenanceWindow $window): void
    {
        Maintenance::flush();
    }

    public function deleted(MaintenanceWindow $window): void
    {
        Maintenance::flush();
    }
}
