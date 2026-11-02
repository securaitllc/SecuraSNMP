<?php

return [
    /*
     * How long a poller may go without reaching a device before that counts as a
     * fault rather than a slow sweep.
     *
     * A full SSH sweep of the Massey fleet measured 103 minutes on 6 October, so a
     * device at 100 minutes is healthy and about to be polled. Six hours is ~3.5x
     * that. Detection costs nothing: genuinely failing devices were at a minimum of
     * 1,027 minutes the same day. See App\Services\PollHealthMonitor for why the
     * margin has to be generous — a failing fleet lengthens the sweep.
     */
    'stale_minutes' => (int) env('POLL_STALE_MINUTES', 360),
];
