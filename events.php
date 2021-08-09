<?php
declare (strict_types=1);

$dbFile = __DIR__ . '/db.json';

$botLogins = [
    'dependabot[bot]'
];

if (file_exists($dbFile)) {
    $db = json_decode(file_get_contents($dbFile), true);
} else {
    $db = [
        'common_count' => 0,
        'common_released' => 0,
        'app_count' => 0,
        'app_released' => 0,
    ];
}

$repsHtml = file_get_contents('https://www.yiiframework.com/status/3.0');

preg_match_all('~<tr data-key="\d+"><td><a href="https://github.com/yiisoft/(.+?)/">~', $repsHtml, $matches);

$reps = $matches[1];

$events = json_decode(file_get_contents('https://api.github.com/orgs/yiisoft/events?per_page=100&page=1', false, stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            'User-Agent: PHP',
        ],
    ],
])), true);

foreach ($events as $event) {
    $repo = str_replace('yiisoft/', '', $event['repo']['name']);

    if (in_array($repo, $reps, true) === false) {
        continue;
    }

    if (in_array($event['actor']['login'], $botLogins, true)) {
        continue;
    }

    $eventDate = date('Y-m-d', strtotime($event['created_at']) + (60 * 60 * 3)); // Москва GMT+3

    if (array_key_exists($eventDate, $db) === false) {
        $db[$eventDate] = [
            'issue_opened' => [],
            'issue_closed' => [],
            'pr_opened' => [],
            'pr_closed' => [],
            'pr_merged' => [],
        ];
    }

    if ($event['type'] === 'IssuesEvent') {
        $action = $event['payload']['action'];

        if (
            ($action === 'opened' || $action === 'reopened') &&
            in_array($event['id'], $db[$eventDate]['issue_opened'], true) === false
        ) {
            $db[$eventDate]['issue_opened'][] = $event['id'];
        } elseif (
            $action === 'closed' &&
            in_array($event['id'], $db[$eventDate]['issue_closed'], true) === false
        ) {
            $db[$eventDate]['issue_closed'][] = $event['id'];
        }
    } elseif ($event['type'] === 'PullRequestEvent') {
        $action = $event['payload']['action'];

        if (
            ($action === 'opened' || $action === 'reopened') &&
            in_array($event['id'], $db[$eventDate]['pr_opened'], true) === false
        ) {
            $db[$eventDate]['pr_opened'][] = $event['id'];
        } elseif ($action === 'closed') {
            if (
                $event['payload']['pull_request']['merged'] &&
                in_array($event['id'], $db[$eventDate]['pr_merged'], true) === false
            ) {
                $db[$eventDate]['pr_merged'][] = $event['id'];
            } elseif (in_array($event['id'], $db[$eventDate]['pr_closed'], true) === false) {
                $db[$eventDate]['pr_closed'][] = $event['id'];
            }
        }
    }
}

file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
