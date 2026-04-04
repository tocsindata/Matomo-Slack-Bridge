<?php
/**
 * Project: stats.example.com
 * Framework: Standalone PHP CLI Script
 * File: matomo_slack_report.php
 * Date: 2026-04-02
 * Copyright: (c) TocsinData.com
 */

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
| Adjust only the values in this section.
| This script is intended to be run from cron every minute.
|
| Example cron:
| * * * * * /usr/bin/php /full/path/matomo_slack_report.php >> /dev/null 2>&1
|
| Force test run:
| /usr/bin/php /full/path/matomo_slack_report.php --force
|
| Force test run without posting to Slack:
| /usr/bin/php /full/path/matomo_slack_report.php --force --dry-run
|--------------------------------------------------------------------------
*/

$matomo_base_url           = 'https://stats.example.com/index.php?module=API';
$matomo_token              = 'REPLACE_WITH_MATOMO_TOKEN';
$matomo_timeout_seconds    = 30;
$matomo_verify_ssl         = true;

$slack_webhook_url         = 'REPLACE_WITH_SLACK_WEBHOOK_URL';
$slack_channel             = ''; // Optional. Many Slack incoming webhooks ignore channel overrides unless explicitly allowed.
$slack_username            = 'Matomo Stats Bot';
$slack_icon_emoji          = ':bar_chart:';
$slack_timeout_seconds     = 20;
$slack_verify_ssl          = true;

$timezone_name             = 'America/Los_Angeles';
$allowed_weekdays          = [1, 2, 3, 4, 5]; // 1=Mon ... 7=Sun
$skip_deleted_sites        = true;
$deleted_site_name_regex   = '/\(DELETED-\d+\)/i';

$script_title              = 'Matomo Site Report';
$message_prefix            = ':bar_chart:';
$include_top_channel       = true;
$include_top_page          = true;
$include_change_percent    = true;
$include_rotation_details  = true;
$include_week_basics       = true;
$include_month_basics      = true;
$max_page_url_length       = 120;

$site_include_ids          = []; // Example: [1, 4, 9] to only allow these ids
$site_exclude_ids          = []; // Example: [22, 77]
$site_include_name_regex   = ''; // Example: '/^(Site A|Site B)$/i'
$site_exclude_name_regex   = ''; // Example: '/staging|dev/i'

$default_schedule_ladder = [
    [
        'label'         => 'Hourly, business hours',
        'interval_mins' => 60,
        'start_hour'    => 8,
        'end_hour'      => 17, // inclusive
    ],
    [
        'label'         => 'Every 30 minutes, business hours',
        'interval_mins' => 30,
        'start_hour'    => 8,
        'end_hour'      => 17,
    ],
    [
        'label'         => 'Every 15 minutes, business hours',
        'interval_mins' => 15,
        'start_hour'    => 8,
        'end_hour'      => 17,
    ],
    [
        'label'         => 'Every 15 minutes, extended hours',
        'interval_mins' => 15,
        'start_hour'    => 7,
        'end_hour'      => 19,
    ],
    [
        'label'         => 'Every 15 minutes, wider extended hours',
        'interval_mins' => 15,
        'start_hour'    => 6,
        'end_hour'      => 20,
    ],
    [
        'label'         => 'Every 10 minutes, wider extended hours',
        'interval_mins' => 10,
        'start_hour'    => 6,
        'end_hour'      => 20,
    ],
];

/*
|--------------------------------------------------------------------------
| BOOT
|--------------------------------------------------------------------------
*/

date_default_timezone_set($timezone_name);

$cli = parseCliArgs($_SERVER['argv'] ?? []);
$now = new DateTimeImmutable('now', new DateTimeZone($timezone_name));

try {
    assertRequiredConfig($matomo_base_url, $matomo_token, $slack_webhook_url);

    $sites = matomoApiRequest(
        $matomo_base_url,
        [
            'method'     => 'SitesManager.getSitesWithAtLeastViewAccess',
            'format'     => 'JSON',
            'token_auth' => $matomo_token,
        ],
        $matomo_timeout_seconds,
        $matomo_verify_ssl
    );

    if (!is_array($sites)) {
        throw new RuntimeException('Matomo site list response was not an array.');
    }

    $eligibleSites = filterEligibleSites(
        $sites,
        $skip_deleted_sites,
        $deleted_site_name_regex,
        $site_include_ids,
        $site_exclude_ids,
        $site_include_name_regex,
        $site_exclude_name_regex
    );

    usort($eligibleSites, static function (array $a, array $b): int {
        return ((int)($a['idsite'] ?? 0)) <=> ((int)($b['idsite'] ?? 0));
    });

    $eligibleCount = count($eligibleSites);

    if ($eligibleCount === 0) {
        $text = $message_prefix . " {$script_title}\n"
            . "Status: skipped\n"
            . "Reason: no eligible Matomo sites were found after filtering.";

        postSlackMessage(
            $slack_webhook_url,
            $text,
            $slack_channel,
            $slack_username,
            $slack_icon_emoji,
            $slack_timeout_seconds,
            $slack_verify_ssl,
            $cli['dry_run']
        );

        exit(0);
    }

    $schedule = chooseAdaptiveSchedule(
        $default_schedule_ladder,
        $allowed_weekdays,
        (int)$now->format('Y'),
        (int)$now->format('n'),
        $eligibleCount,
        $timezone_name
    );

    $monthSlots = buildMonthSlots(
        $schedule,
        $allowed_weekdays,
        (int)$now->format('Y'),
        (int)$now->format('n'),
        $timezone_name
    );

    if (count($monthSlots) === 0) {
        throw new RuntimeException('No valid reporting slots exist for the current month and schedule.');
    }

    $isSendTime = isCurrentMinuteAValidSendBoundary($now, $schedule, $allowed_weekdays);

    if (!$cli['force'] && !$isSendTime) {
        exit(0);
    }

    $currentSlotIndex = determineCurrentSlotIndex($monthSlots, $now);
    $selectedIndex    = $currentSlotIndex % $eligibleCount;
    $selectedSite     = $eligibleSites[$selectedIndex];

    $siteId   = (int)($selectedSite['idsite'] ?? 0);
    $siteName = trim((string)($selectedSite['name'] ?? 'Unknown Site'));
    $siteUrl  = normalizeSiteUrl($selectedSite['main_url'] ?? '');

    $todaySummary = safeMatomoCall(
        $matomo_base_url,
        [
            'method'     => 'VisitsSummary.get',
            'idSite'     => $siteId,
            'period'     => 'day',
            'date'       => 'today',
            'format'     => 'JSON',
            'token_auth' => $matomo_token,
        ],
        $matomo_timeout_seconds,
        $matomo_verify_ssl
    );

    $yesterdaySummary = safeMatomoCall(
        $matomo_base_url,
        [
            'method'     => 'VisitsSummary.get',
            'idSite'     => $siteId,
            'period'     => 'day',
            'date'       => 'yesterday',
            'format'     => 'JSON',
            'token_auth' => $matomo_token,
        ],
        $matomo_timeout_seconds,
        $matomo_verify_ssl
    );

    $weekSummary = [];
    if ($include_week_basics) {
        $weekSummary = safeMatomoCall(
            $matomo_base_url,
            [
                'method'     => 'VisitsSummary.get',
                'idSite'     => $siteId,
                'period'     => 'range',
                'date'       => getWeekDateRange($now),
                'format'     => 'JSON',
                'token_auth' => $matomo_token,
            ],
            $matomo_timeout_seconds,
            $matomo_verify_ssl
        );
    }

    $monthSummary = [];
    if ($include_month_basics) {
        $monthSummary = safeMatomoCall(
            $matomo_base_url,
            [
                'method'     => 'VisitsSummary.get',
                'idSite'     => $siteId,
                'period'     => 'range',
                'date'       => getMonthDateRange($now),
                'format'     => 'JSON',
                'token_auth' => $matomo_token,
            ],
            $matomo_timeout_seconds,
            $matomo_verify_ssl
        );
    }

    $topChannel = null;
    if ($include_top_channel) {
        $topChannel = fetchTopChannel(
            $matomo_base_url,
            $matomo_token,
            $siteId,
            $matomo_timeout_seconds,
            $matomo_verify_ssl
        );
    }

    $topPage = null;
    if ($include_top_page) {
        $topPage = fetchTopPage(
            $matomo_base_url,
            $matomo_token,
            $siteId,
            $matomo_timeout_seconds,
            $matomo_verify_ssl,
            $max_page_url_length
        );
    }

    $text = buildSlackReportText(
        $message_prefix,
        $script_title,
        $siteId,
        $siteName,
        $siteUrl,
        $now,
        $todaySummary,
        $yesterdaySummary,
        $weekSummary,
        $monthSummary,
        $topChannel,
        $topPage,
        $schedule,
        $currentSlotIndex,
        count($monthSlots),
        $selectedIndex,
        $eligibleCount,
        (bool)$include_change_percent,
        (bool)$include_rotation_details,
        (bool)$include_week_basics,
        (bool)$include_month_basics
    );

    postSlackMessage(
        $slack_webhook_url,
        $text,
        $slack_channel,
        $slack_username,
        $slack_icon_emoji,
        $slack_timeout_seconds,
        $slack_verify_ssl,
        $cli['dry_run']
    );

    writeStdout('[' . $now->format('Y-m-d H:i:s T') . "] Sent report for site {$siteId} ({$siteName})\n");
    exit(0);
} catch (Throwable $e) {
    $errorText = $message_prefix . " {$script_title}\n"
        . "Status: error\n"
        . 'Time: ' . $now->format('Y-m-d H:i:s T') . "\n"
        . 'Message: ' . $e->getMessage();

    try {
        postSlackMessage(
            $slack_webhook_url,
            $errorText,
            $slack_channel,
            $slack_username,
            $slack_icon_emoji,
            $slack_timeout_seconds,
            $slack_verify_ssl,
            $cli['dry_run']
        );
    } catch (Throwable $inner) {
        // Intentionally swallow Slack-post failures here.
    }

    writeStderr('[' . $now->format('Y-m-d H:i:s T') . '] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/*
|--------------------------------------------------------------------------
| FUNCTIONS
|--------------------------------------------------------------------------
*/

function parseCliArgs(array $argv): array
{
    $args = [
        'force'   => false,
        'dry_run' => false,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--force') {
            $args['force'] = true;
        }

        if ($arg === '--dry-run') {
            $args['dry_run'] = true;
        }
    }

    return $args;
}

function writeStdout(string $message): void
{
    if (defined('STDOUT')) {
        fwrite(STDOUT, $message);
        return;
    }

    echo $message;
}

function writeStderr(string $message): void
{
    if (defined('STDERR')) {
        fwrite(STDERR, $message);
        return;
    }

    error_log(rtrim($message));
}

function assertRequiredConfig(string $matomoBaseUrl, string $matomoToken, string $slackWebhookUrl): void
{
    if (trim($matomoBaseUrl) === '') {
        throw new InvalidArgumentException('Configuration error: $matomo_base_url is empty.');
    }

    if (trim($matomoToken) === '' || $matomoToken === 'REPLACE_WITH_MATOMO_TOKEN') {
        throw new InvalidArgumentException('Configuration error: $matomo_token is not set.');
    }

    if (trim($slackWebhookUrl) === '' || $slackWebhookUrl === 'REPLACE_WITH_SLACK_WEBHOOK_URL') {
        throw new InvalidArgumentException('Configuration error: $slack_webhook_url is not set.');
    }
}

/**
 * Matomo API request helper.
 * token_auth is sent as a POST field instead of in the URL query string.
 */
function matomoApiRequest(
    string $baseUrl,
    array $params,
    int $timeoutSeconds,
    bool $verifySsl
) {
    $postFields = [];

    if (array_key_exists('token_auth', $params)) {
        $postFields['token_auth'] = (string)$params['token_auth'];
        unset($params['token_auth']);
    }

    $queryString = http_build_query($params);
    $url = $baseUrl;

    if ($queryString !== '') {
        $url .= '&' . $queryString;
    }

    $headers = [
        'Accept: application/json, text/plain, */*',
    ];

    $curlOptions = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        CURLOPT_USERAGENT      => 'TocsinData Matomo Slack Reporter/1.0',
    ];

    if (!empty($postFields)) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $curlOptions[CURLOPT_POST] = true;
        $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($postFields);
    }

    $curlOptions[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL for Matomo request.');
    }

    curl_setopt_array($ch, $curlOptions);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Matomo API cURL error: ' . $error);
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new RuntimeException('Matomo API HTTP error: ' . $httpCode);
    }

    $trimmed = trim((string)$response);

    if ($trimmed === '') {
        throw new RuntimeException('Matomo API returned an empty response.');
    }

    $json = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_array($json) && isset($json['result']) && $json['result'] === 'error') {
            $message = (string)($json['message'] ?? 'Unknown Matomo API error.');
            throw new RuntimeException('Matomo API error: ' . $message);
        }

        return $json;
    }

    if (stripos($trimmed, '<error') !== false || stripos($trimmed, '<result>') !== false) {
        if (preg_match('/message="([^"]+)"/i', $trimmed, $matches)) {
            throw new RuntimeException('Matomo API XML error: ' . html_entity_decode($matches[1], ENT_QUOTES));
        }

        throw new RuntimeException('Matomo API returned XML instead of JSON.');
    }

    throw new RuntimeException('Matomo API returned invalid JSON.');
}

function safeMatomoCall(
    string $baseUrl,
    array $params,
    int $timeoutSeconds,
    bool $verifySsl
): array {
    $response = matomoApiRequest($baseUrl, $params, $timeoutSeconds, $verifySsl);

    if (!is_array($response)) {
        return [];
    }

    return $response;
}

function filterEligibleSites(
    array $sites,
    bool $skipDeletedSites,
    string $deletedSiteNameRegex,
    array $siteIncludeIds,
    array $siteExcludeIds,
    string $siteIncludeNameRegex,
    string $siteExcludeNameRegex
): array {
    $eligible = [];

    foreach ($sites as $site) {
        if (!is_array($site)) {
            continue;
        }

        $siteId   = (int)($site['idsite'] ?? 0);
        $siteName = trim((string)($site['name'] ?? ''));

        if ($siteId <= 0) {
            continue;
        }

        if ($siteName === '') {
            continue;
        }

        if ($skipDeletedSites && preg_match($deletedSiteNameRegex, $siteName)) {
            continue;
        }

        if (!empty($siteIncludeIds) && !in_array($siteId, $siteIncludeIds, true)) {
            continue;
        }

        if (!empty($siteExcludeIds) && in_array($siteId, $siteExcludeIds, true)) {
            continue;
        }

        if ($siteIncludeNameRegex !== '' && !preg_match($siteIncludeNameRegex, $siteName)) {
            continue;
        }

        if ($siteExcludeNameRegex !== '' && preg_match($siteExcludeNameRegex, $siteName)) {
            continue;
        }

        $eligible[] = $site;
    }

    return $eligible;
}

function chooseAdaptiveSchedule(
    array $scheduleLadder,
    array $allowedWeekdays,
    int $year,
    int $month,
    int $eligibleSiteCount,
    string $timezoneName
): array {
    $selected = null;

    foreach ($scheduleLadder as $schedule) {
        $slots = buildMonthSlots($schedule, $allowedWeekdays, $year, $month, $timezoneName);
        $capacity = count($slots);

        if ($capacity <= 0) {
            continue;
        }

        $schedule['monthly_capacity'] = $capacity;

        if ($eligibleSiteCount <= $capacity) {
            $selected = $schedule;
            break;
        }

        $selected = $schedule;
    }

    if ($selected === null) {
        throw new RuntimeException('No valid adaptive schedule could be selected.');
    }

    return $selected;
}

function buildMonthSlots(
    array $schedule,
    array $allowedWeekdays,
    int $year,
    int $month,
    string $timezoneName
): array {
    $tz = new DateTimeZone($timezoneName);
    $startOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
    $endOfMonth = $startOfMonth->modify('first day of next month')->modify('-1 second');

    $intervalMins = (int)$schedule['interval_mins'];
    $startHour    = (int)$schedule['start_hour'];
    $endHour      = (int)$schedule['end_hour'];

    $slots = [];
    $dayCursor = $startOfMonth;

    while ($dayCursor <= $endOfMonth) {
        $weekday = (int)$dayCursor->format('N');

        if (in_array($weekday, $allowedWeekdays, true)) {
            for ($hour = $startHour; $hour <= $endHour; $hour++) {
                for ($minute = 0; $minute < 60; $minute += $intervalMins) {
                    $slot = $dayCursor->setTime($hour, $minute, 0);

                    if ((int)$slot->format('n') === $month) {
                        $slots[] = $slot;
                    }
                }
            }
        }

        $dayCursor = $dayCursor->modify('+1 day')->setTime(0, 0, 0);
    }

    usort($slots, static function (DateTimeImmutable $a, DateTimeImmutable $b): int {
        return $a <=> $b;
    });

    return $slots;
}

function isCurrentMinuteAValidSendBoundary(
    DateTimeImmutable $now,
    array $schedule,
    array $allowedWeekdays
): bool {
    $weekday = (int)$now->format('N');
    if (!in_array($weekday, $allowedWeekdays, true)) {
        return false;
    }

    $hour = (int)$now->format('G');
    $minute = (int)$now->format('i');

    $startHour    = (int)$schedule['start_hour'];
    $endHour      = (int)$schedule['end_hour'];
    $intervalMins = (int)$schedule['interval_mins'];

    if ($hour < $startHour || $hour > $endHour) {
        return false;
    }

    return ($minute % $intervalMins) === 0;
}

function determineCurrentSlotIndex(array $slots, DateTimeImmutable $now): int
{
    $count = count($slots);
    if ($count === 0) {
        return 0;
    }

    $lastIndex = 0;

    foreach ($slots as $index => $slot) {
        if ($slot <= $now) {
            $lastIndex = $index;
            continue;
        }

        break;
    }

    return $lastIndex;
}

function getWeekDateRange(DateTimeImmutable $now): string
{
    $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
    $weekEnd   = $now->setTime(23, 59, 59);

    return $weekStart->format('Y-m-d') . ',' . $weekEnd->format('Y-m-d');
}

function getMonthDateRange(DateTimeImmutable $now): string
{
    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $monthEnd   = $now->setTime(23, 59, 59);

    return $monthStart->format('Y-m-d') . ',' . $monthEnd->format('Y-m-d');
}

function fetchTopChannel(
    string $baseUrl,
    string $token,
    int $siteId,
    int $timeoutSeconds,
    bool $verifySsl
): ?string {
    $response = safeMatomoCall(
        $baseUrl,
        [
            'method'       => 'Referrers.getReferrerType',
            'idSite'       => $siteId,
            'period'       => 'day',
            'date'         => 'today',
            'format'       => 'JSON',
            'filter_limit' => 10,
            'token_auth'   => $token,
        ],
        $timeoutSeconds,
        $verifySsl
    );

    if (!is_array($response) || empty($response)) {
        return null;
    }

    $bestLabel = null;
    $bestVisits = -1;

    foreach ($response as $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = trim((string)($row['label'] ?? $row['name'] ?? ''));
        $visits = (int)($row['nb_visits'] ?? $row['visits'] ?? 0);

        if ($label === '') {
            continue;
        }

        if ($visits > $bestVisits) {
            $bestVisits = $visits;
            $bestLabel = $label;
        }
    }

    return $bestLabel;
}

function fetchTopPage(
    string $baseUrl,
    string $token,
    int $siteId,
    int $timeoutSeconds,
    bool $verifySsl,
    int $maxPageUrlLength
): ?string {
    $response = safeMatomoCall(
        $baseUrl,
        [
            'method'       => 'Actions.getPageUrls',
            'idSite'       => $siteId,
            'period'       => 'day',
            'date'         => 'today',
            'format'       => 'JSON',
            'flat'         => 1,
            'filter_limit' => 10,
            'token_auth'   => $token,
        ],
        $timeoutSeconds,
        $verifySsl
    );

    if (!is_array($response) || empty($response)) {
        return null;
    }

    $bestLabel = null;
    $bestHits = -1;

    foreach ($response as $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = trim((string)($row['label'] ?? $row['url'] ?? ''));
        $hits = (int)($row['nb_hits'] ?? $row['nb_pageviews'] ?? $row['hits'] ?? 0);

        if ($label === '') {
            continue;
        }

        if ($hits > $bestHits) {
            $bestHits = $hits;
            $bestLabel = $label;
        }
    }

    if ($bestLabel === null) {
        return null;
    }

    return mb_strimwidth($bestLabel, 0, $maxPageUrlLength, '…', 'UTF-8');
}

function normalizeSiteUrl($url): string
{
    return trim((string)$url);
}

function buildSlackReportText(
    string $messagePrefix,
    string $scriptTitle,
    int $siteId,
    string $siteName,
    string $siteUrl,
    DateTimeImmutable $now,
    array $todaySummary,
    array $yesterdaySummary,
    array $weekSummary,
    array $monthSummary,
    ?string $topChannel,
    ?string $topPage,
    array $schedule,
    int $currentSlotIndex,
    int $monthSlotCount,
    int $selectedIndex,
    int $eligibleCount,
    bool $includeChangePercent,
    bool $includeRotationDetails,
    bool $includeWeekBasics,
    bool $includeMonthBasics
): string {
    $todayVisits          = (int)($todaySummary['nb_visits'] ?? 0);
    $todayUniqueVisitors  = (int)($todaySummary['nb_uniq_visitors'] ?? 0);
    $todayPageviews       = (int)($todaySummary['nb_pageviews'] ?? 0);
    $todayActions         = (int)($todaySummary['nb_actions'] ?? 0);
    $todayBounceRate      = normalizePercent($todaySummary['bounce_rate'] ?? null);
    $todayAvgTime         = (int)($todaySummary['avg_time_on_site'] ?? 0);
    $todayActionsPerVisit = normalizeFloat($todaySummary['nb_actions_per_visit'] ?? null);

    if ($todayActionsPerVisit === null && $todayVisits > 0 && $todayActions > 0) {
        $todayActionsPerVisit = round($todayActions / $todayVisits, 2);
    }

    $yesterdayVisits         = (int)($yesterdaySummary['nb_visits'] ?? 0);
    $yesterdayUniqueVisitors = (int)($yesterdaySummary['nb_uniq_visitors'] ?? 0);
    $yesterdayPageviews      = (int)($yesterdaySummary['nb_pageviews'] ?? 0);
    $yesterdayActions        = (int)($yesterdaySummary['nb_actions'] ?? 0);
    $yesterdayBounceRate     = normalizePercent($yesterdaySummary['bounce_rate'] ?? null);
    $yesterdayAvgTime        = (int)($yesterdaySummary['avg_time_on_site'] ?? 0);

    $weekVisits         = (int)($weekSummary['nb_visits'] ?? 0);
    $weekUniqueVisitors = (int)($weekSummary['nb_uniq_visitors'] ?? 0);
    $weekPageviews      = (int)($weekSummary['nb_pageviews'] ?? 0);

    $monthVisits         = (int)($monthSummary['nb_visits'] ?? 0);
    $monthUniqueVisitors = (int)($monthSummary['nb_uniq_visitors'] ?? 0);
    $monthPageviews      = (int)($monthSummary['nb_pageviews'] ?? 0);

    $lines = [];
    $lines[] = "{$messagePrefix} {$scriptTitle}";
    $lines[] = 'Time: ' . $now->format('Y-m-d H:i:s T');
    $lines[] = 'Site: ' . $siteName;
    $lines[] = 'ID: ' . $siteId;

    if ($siteUrl !== '') {
        $lines[] = 'URL: ' . $siteUrl;
    }

    $lines[] = '';
    $lines[] = 'Today so far';
    $lines[] = '- Visits: ' . number_format($todayVisits);
    $lines[] = '- Unique visitors: ' . number_format($todayUniqueVisitors);

    if ($todayPageviews > 0) {
        $lines[] = '- Pageviews: ' . number_format($todayPageviews);
    }

    $lines[] = '- Actions: ' . number_format($todayActions);

    if ($todayActionsPerVisit !== null) {
        $lines[] = '- Actions per visit: ' . number_format($todayActionsPerVisit, 2);
    }

    if ($todayBounceRate !== null) {
        $lines[] = '- Bounce rate: ' . $todayBounceRate . '%';
    }

    $lines[] = '- Avg visit duration: ' . formatDuration($todayAvgTime);

    $lines[] = '';
    $lines[] = 'Yesterday full day';
    $lines[] = '- Visits: ' . number_format($yesterdayVisits);
    $lines[] = '- Unique visitors: ' . number_format($yesterdayUniqueVisitors);

    if ($yesterdayPageviews > 0) {
        $lines[] = '- Pageviews: ' . number_format($yesterdayPageviews);
    }

    $lines[] = '- Actions: ' . number_format($yesterdayActions);

    if ($yesterdayBounceRate !== null) {
        $lines[] = '- Bounce rate: ' . $yesterdayBounceRate . '%';
    }

    $lines[] = '- Avg visit duration: ' . formatDuration($yesterdayAvgTime);

    if ($includeWeekBasics) {
        $lines[] = '';
        $lines[] = 'Week to date';
        $lines[] = '- Visits: ' . number_format($weekVisits);
        $lines[] = '- Unique visitors: ' . number_format($weekUniqueVisitors);

        if ($weekPageviews > 0) {
            $lines[] = '- Pageviews: ' . number_format($weekPageviews);
        }
    }

    if ($includeMonthBasics) {
        $lines[] = '';
        $lines[] = 'Month to date';
        $lines[] = '- Visits: ' . number_format($monthVisits);
        $lines[] = '- Unique visitors: ' . number_format($monthUniqueVisitors);

        if ($monthPageviews > 0) {
            $lines[] = '- Pageviews: ' . number_format($monthPageviews);
        }
    }

    if ($includeChangePercent) {
        $lines[] = '';
        $lines[] = 'Comparison';
        $lines[] = '- Today vs yesterday visits: ' . formatChangePercent($todayVisits, $yesterdayVisits);
    }

    if ($topChannel !== null || $topPage !== null) {
        $lines[] = '';
        $lines[] = 'Additional detail';

        if ($topChannel !== null) {
            $lines[] = '- Top channel today: ' . $topChannel;
        }

        if ($topPage !== null) {
            $lines[] = '- Top page today: ' . $topPage;
        }
    }

    $lines[] = '';
    $lines[] = 'Status: ' . determineStatus($todayVisits, $yesterdayVisits);

    if ($includeRotationDetails) {
        $lines[] = '';
        $lines[] = 'Rotation';
        $lines[] = '- Schedule: ' . (string)($schedule['label'] ?? 'Unknown');
        $lines[] = '- Interval: ' . (int)($schedule['interval_mins'] ?? 0) . ' minutes';
        $lines[] = '- Hours: ' . sprintf('%02d:00-%02d:59', (int)$schedule['start_hour'], (int)$schedule['end_hour']);
        $lines[] = '- Monthly slot: ' . ($currentSlotIndex + 1) . ' of ' . $monthSlotCount;
        $lines[] = '- Eligible site index: ' . ($selectedIndex + 1) . ' of ' . $eligibleCount;
        $lines[] = '- Monthly capacity: ' . number_format((int)($schedule['monthly_capacity'] ?? $monthSlotCount));
    }

    return implode("\n", $lines);
}

function normalizePercent($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_string($value)) {
        $value = str_replace('%', '', $value);
    }

    if (!is_numeric($value)) {
        return null;
    }

    return number_format((float)$value, 2);
}

function normalizeFloat($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (float)$value;
}

function formatDuration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0s';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    $parts = [];

    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }

    if ($minutes > 0) {
        $parts[] = $minutes . 'm';
    }

    if ($secs > 0 || empty($parts)) {
        $parts[] = $secs . 's';
    }

    return implode(' ', $parts);
}

function formatChangePercent(int $current, int $previous): string
{
    if ($previous <= 0 && $current <= 0) {
        return '0.00%';
    }

    if ($previous <= 0 && $current > 0) {
        return 'new traffic / no prior baseline';
    }

    $change = (($current - $previous) / $previous) * 100;

    if ($change > 0) {
        return '+' . number_format($change, 2) . '%';
    }

    return number_format($change, 2) . '%';
}

function determineStatus(int $todayVisits, int $yesterdayVisits): string
{
    if ($todayVisits === 0) {
        return 'ALERT - zero visits today so far';
    }

    if ($yesterdayVisits > 0) {
        $ratio = $todayVisits / $yesterdayVisits;

        if ($ratio <= 0.25) {
            return 'ELEVATED - materially below yesterday';
        }

        if ($ratio >= 2.00) {
            return 'NOTICE - materially above yesterday';
        }
    }

    return 'Normal';
}

function postSlackMessage(
    string $webhookUrl,
    string $text,
    string $channel,
    string $username,
    string $iconEmoji,
    int $timeoutSeconds,
    bool $verifySsl,
    bool $dryRun = false
): void {
    $payload = [
        'text'       => $text,
        'username'   => $username,
        'icon_emoji' => $iconEmoji,
    ];

    if (trim($channel) !== '') {
        $payload['channel'] = $channel;
    }

    if ($dryRun) {
        writeStdout("DRY RUN - Slack payload follows:\n");
        writeStdout($text . "\n");
        return;
    }

    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL for Slack request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $webhookUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Slack webhook cURL error: ' . $error);
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new RuntimeException('Slack webhook HTTP error: ' . $httpCode . ' response: ' . trim((string)$response));
    }
}
