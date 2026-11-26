<?php

use App\Services\WebinarEmailService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('webinars:dispatch-emails', function (WebinarEmailService $emails) {
    $counts = $emails->dispatchDue();
    $this->info(sprintf(
        'Dispatched reminders=%d followups=%d replay_followups=%d',
        $counts['reminders'],
        $counts['followups'],
        $counts['replay_followups']
    ));
})->purpose('Dispatch due webinar reminders (10m before) and follow-ups (30m after)');

Schedule::command('webinars:dispatch-emails')->everyMinute();
