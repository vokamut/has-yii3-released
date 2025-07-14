<?php
declare (strict_types=1);

final class Run
{
    private $test;

    private $envFile = __DIR__ . '/env.json';
    private $dbFile = __DIR__ . '/db.json';
    private $publicDbFile = __DIR__ . '/public/db.json';

    private $db = [];

    private $publicJsonData = [];

    private $message = 'Нет.';

    private $botToken;

    private $groupChatId;
    private $privateChatId;

    private $emoji;

    public function __construct(bool $test)
    {
        if (file_exists($this->envFile) === false) {
            throw new RuntimeException('File [' . $this->envFile . '] not found');
        }

        $env = json_decode(file_get_contents($this->envFile), true);

        $this->botToken = $env['botToken'];
        $this->groupChatId = $env['groupChatId'];
        $this->privateChatId = $env['privateChatId'];

        $this->test = $test;

        if (file_exists($this->dbFile)) {
            $this->db = json_decode(file_get_contents($this->dbFile), true);
        }

        $this->emoji = json_decode('"\ud83d\udca5"', true); // Взрыв
    }

    public function run(): void
    {
        $this->generateMessage();
        $this->generatePublicDb();
        $this->send();

        if (!$this->test) {
            ksort($this->db);

            file_put_contents($this->dbFile, json_encode($this->db, JSON_PRETTY_PRINT));
            file_put_contents($this->publicDbFile, json_encode($this->publicJsonData, JSON_PRETTY_PRINT));
        }
    }

    private function generateMessage(): void
    {
        // Статус всех пакетов
        $yii3ProgressHtml = file_get_contents('https://www.yiiframework.com/yii3-progress');

        preg_match('~<h2>Rele\w+ <b>(\d+)/(\d+)</b> pack\w+</h2>~', $yii3ProgressHtml, $matches);

        if (array_key_exists(1, $matches) === false || array_key_exists(2, $matches) === false) {
            return;
        }

        $allReleased = $matches[1];
        $allCount = $matches[2];

        // Статус релиза app
        $yii3app = json_decode(file_get_contents('https://raw.githubusercontent.com/yiisoft/app/master/composer.json'), true);

        $appCount = 0;
        $appReleased = 0;

        foreach ($yii3app['require'] as $package => $version) {
            if (strpos($package, 'yiisoft/') !== 0) {
                continue;
            }

            ++$appCount;

            if (strpos($version, 'dev') === false) {
                ++$appReleased;
            }
        }

        foreach ($yii3app['require-dev'] as $package => $version) {
            if (strpos($package, 'yiisoft/') !== 0) {
                continue;
            }

            ++$appCount;

            if (strpos($version, 'dev') === false) {
                ++$appReleased;
            }
        }

        if ($allCount === $allReleased) {
            $this->message = 'ДА! ' . $this->emoji;
        }

        $this->message .= PHP_EOL . 'Прогресс: ' . $appReleased . '/' . $appCount . ' (' . round($appReleased / $appCount * 100) . '%)';

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', time() - (60 * 60 * 24));

        if (
            array_key_exists('app_count', $this->db[$yesterday]) &&
            array_key_exists('app_released', $this->db[$yesterday]) &&
            $this->db[$yesterday]['app_count'] !== 0 &&
            $this->db[$yesterday]['app_released'] !== 0 &&
            (
                $this->db[$yesterday]['app_count'] !== $appCount ||
                $this->db[$yesterday]['app_released'] !== $appReleased
            )
        ) {
            $this->message .= ' ' . $this->emoji;
        }

        $this->message .= PHP_EOL . 'Прогресс всех пакетов: ' . $allReleased . '/' . $allCount . ' (' . round($allReleased / $allCount * 100) . '%)';

        if (
            array_key_exists('common_released', $this->db[$yesterday]) &&
            array_key_exists('common_count', $this->db[$yesterday]) &&
            $this->db[$yesterday]['common_released'] !== 0 &&
            $this->db[$yesterday]['common_count'] !== 0 &&
            (
                $this->db[$yesterday]['common_released'] !== $allReleased ||
                $this->db[$yesterday]['common_count'] !== $allCount
            )
        ) {
            $this->message .= ' ' . $this->emoji;
        }

        $this->db[$today]['common_released'] = $allReleased;
        $this->db[$today]['common_count'] = $allCount;
        $this->db[$today]['app_count'] = $appCount;
        $this->db[$today]['app_released'] = $appReleased;

        // Статус PR и Issue
        $issueOpened = count($this->db[$yesterday]['issue_opened']);
        $issueClosed = count($this->db[$yesterday]['issue_closed']);
        $prOpened = count($this->db[$yesterday]['pr_opened']);
        $prMerged = count($this->db[$yesterday]['pr_merged']);
        $prRejected = count($this->db[$yesterday]['pr_rejected']);

        $issueMessages = [];
        $prMessages = [];

        if ($issueOpened !== 0) {
            $issueMessages[] = ' ' . $issueOpened . ' ' . $this->pluralize($issueOpened, ['открыт', 'открыто', 'открытых']);
        }

        if ($issueClosed !== 0) {
            $issueMessages[] = ' ' . $issueClosed . ' ' . $this->pluralize($issueClosed, ['закрыт', 'закрыто', 'закрытых']);
        }

        $this->message .= PHP_EOL . 'Issue:';

        if (count($issueMessages) > 0) {
            $this->message .= implode(',', $issueMessages) . '.';
        } else {
            $this->message .= ' активности не было.';
        }

        if ($prOpened !== 0) {
            $prMessages[] = ' ' . $prOpened . ' ' . $this->pluralize($prOpened, ['открыт', 'открыто', 'открытых']);
        }

        if ($prMerged !== 0) {
            $prMessages[] = ' ' . $prMerged . ' ' . $this->pluralize($prMerged, ['принят', 'принято', 'принятых']);
        }

        if ($prRejected !== 0) {
            $prMessages[] = ' ' . $prRejected . ' ' . $this->pluralize($prRejected, ['отклонен', 'отклонено', 'отклоненных']);
        }

        $this->message .= PHP_EOL . 'PR:';

        if (count($prMessages) > 0) {
            $this->message .= implode(',', $prMessages) . '.';
        } else {
            $this->message .= ' активности не было.';
        }
    }

    private function generatePublicDb(): void
    {
        foreach ($this->db as $date => $data) {
            if (
                !array_key_exists('common_count', $data) ||
                (int)$data['common_count'] === 0 ||
                !array_key_exists('common_released', $data) ||
                (int)$data['common_released'] === 0
            ) {
                continue;
            }

            $append = [
                'date' => $date,
                'progress' => round($data['common_released'] / $data['common_count'] * 100),
                'progressTitle' => $data['common_released'] . ' / ' . $data['common_count'],
                'release' => round($data['common_released'] / $data['common_count'] * 100),
                'releaseTitle' => $data['common_released'] . ' / ' . $data['common_count'],
                'issuesOpen' => 0,
                'issuesCloset' => 0,
                'prOpen' => 0,
                'prCloset' => 0,
                'prRejected' => 0,
                'prMerged' => 0,
            ];

            if (array_key_exists('app_released', $data)) {
                $append['release'] = round($data['app_released'] / $data['app_count'] * 100);
                $append['releaseTitle'] = $data['app_released'] . ' / ' . $data['app_count'];
            }

            if (array_key_exists('issue_opened', $data)) {
                $append['issuesOpen'] = count($data['issue_opened']);
            }

            if (array_key_exists('issue_closed', $data)) {
                $append['issuesCloset'] = count($data['issue_closed']);
            }

            if (array_key_exists('pr_opened', $data)) {
                $append['prOpen'] = count($data['pr_opened']);
            }

            if (array_key_exists('pr_closed', $data)) {
                $append['prCloset'] = count($data['pr_closed']);
            }

            if (array_key_exists('pr_rejected', $data)) {
                $append['prRejected'] = count($data['pr_rejected']);
            }

            if (array_key_exists('pr_merged', $data)) {
                $append['prMerged'] = count($data['pr_merged']);
            }

            $this->publicJsonData[] = $append;
        }
    }

    private function pluralize(int $count, array $titles): string
    {
        $remOf10 = $count % 10;
        $remOf100 = $count % 100;

        if ($remOf10 === 1 && $remOf100 !== 11) {
            return $titles[0];
        }

        if ($remOf10 >= 2 && $remOf10 <= 4 && ($remOf100 < 10 || $remOf100 >= 20)) {
            return $titles[1];
        }

        return $titles[2];
    }

    private function send(): void
    {
        file_get_contents(
            'https://api.telegram.org/bot' . $this->botToken . '/sendMessage',
            false,
            stream_context_create([
                'http' => [
                    'header' => 'Content-type: application/json',
                    'method' => 'POST',
                    'content' => json_encode([
                        'chat_id' => $this->test ? $this->privateChatId : $this->groupChatId,
                        'text' => $this->message,
                    ]),
                ],
            ])
        );
    }
}

(new Run($argc > 1))->run();
