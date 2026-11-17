<?php

declare(strict_types=1);

use App\Enums\ActivityEventEnum;
use Illuminate\Support\Facades\File;

/**
 * The activity log shows events and log names through `activityLog.events.*` and
 * `activityLog.logs.*`; a missing key leaks the raw snake_case name into the UI (#150).
 */
function activityLogTranslations(string $locale): array
{
    $translations = json_decode(File::get(resource_path("js/locales/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

    return $translations['activityLog'];
}

/**
 * Literal arguments of `->event('…')`, `useLog('…')`, `activity('…')` and `useLogName('…')` in app/.
 *
 * @return array{events: list<string>, logs: list<string>}
 */
function activityNamesWrittenByTheApp(): array
{
    $events = [];
    $logs = [];

    foreach (File::allFiles(app_path()) as $sourceFile) {
        $source = $sourceFile->getContents();

        preg_match_all("/->event\\('([a-z_]+)'\\)/", $source, $eventMatches);
        preg_match_all("/(?:useLog|useLogName|activity)\\('([a-z_-]+)'\\)/", $source, $logMatches);

        $events = [...$events, ...$eventMatches[1]];
        $logs = [...$logs, ...$logMatches[1]];
    }

    return ['events' => array_values(array_unique($events)), 'logs' => array_values(array_unique($logs))];
}

test('every event the app writes is offered in the activity log filter', function () {
    expect(ActivityEventEnum::values())->toContain(...activityNamesWrittenByTheApp()['events']);
});

test('every activity event and log name has a label in each locale', function (string $locale) {
    $translations = activityLogTranslations($locale);

    foreach (ActivityEventEnum::values() as $eventName) {
        expect($translations['events'])->toHaveKey($eventName);
    }

    foreach ([...activityNamesWrittenByTheApp()['logs'], 'default'] as $logName) {
        expect($translations['logs'])->toHaveKey($logName);
    }
})->with(['en', 'it', 'de']);
