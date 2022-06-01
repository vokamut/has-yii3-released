<?php
declare (strict_types=1);

final class Run
{
    private $test;

    private $envFile = __DIR__ . '/env.json';
    private $dbFile = __DIR__ . '/db.json';
    private $publicDbFile = __DIR__ . '/public/db.json';

    private $db = [
        'common_count' => 0,
        'common_released' => 0,
        'app_count' => 0,
        'app_released' => 0,
    ];

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
            krsort($this->db);

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

        if ($appCount === $appReleased) {
            $this->message = 'ДА! ' . $this->emoji;
        }

        $this->message .= PHP_EOL . 'Прогресс: ' . $appReleased . '/' . $appCount . ' (' . round($appReleased / $appCount * 100) . '%)';

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', time() - (60 * 60 * 24));

        if (
            $this->db[$yesterday]['app_count'] !== $appCount ||
            $this->db[$yesterday]['app_released'] !== $appReleased
        ) {
            $this->message .= ' ' . $this->emoji;
        }

        $this->message .= PHP_EOL . 'Прогресс всех пакетов: ' . $matches[1] . '/' . $matches[2] . ' (' . round($matches[1] / $matches[2] * 100) . '%)';

        if (
            $this->db[$yesterday]['common_released'] !== (int)$matches[1] ||
            $this->db[$yesterday]['common_count'] !== (int)$matches[2]
        ) {
            $this->message .= ' ' . $this->emoji;
        }

        $this->db[$today]['common_released'] = (int)$matches[1];
        $this->db[$today]['common_count'] = (int)$matches[2];
        $this->db[$today]['app_count'] = $appCount;
        $this->db[$today]['app_released'] = $appReleased;

        // Статус PR и Issue
        $issueOpened = count($this->db[$yesterday]['issue_opened']);
        $issueClosed = count($this->db[$yesterday]['issue_closed']);
        $prOpened = count($this->db[$yesterday]['pr_opened']);
        $prMerged = count($this->db[$yesterday]['pr_merged']);
        $prClosed = count($this->db[$yesterday]['pr_closed']);

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

        if ($prClosed !== 0) {
            $prMessages[] = ' ' . $prClosed . ' ' . $this->pluralize($prClosed, ['закрыт', 'закрыто', 'закрытых']);
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
            $append = [
                'date' => $date,
                'progress' => round($data['common_released'] / $data['common_count'] * 100),
                'progressTitle' => $data['common_released'] . ' / ' . $data['common_count']
            ];

            if (array_key_exists('app_released', $data)) {
                $append['release'] = round($data['app_released'] / $data['app_count'] * 100);
                $append['releaseTitle'] = $data['app_released'] . ' / ' . $data['app_count'];
            }

            if (array_key_exists('issue_opened', $data)) {
                $append['release'] = count($data['issue_opened']);
            }

            if (array_key_exists('issue_closed', $data)) {
                $append['issuesOpen'] = count($data['issue_closed']);
            }

            if (array_key_exists('pr_opened', $data)) {
                $append['issuesCloset'] = count($data['pr_opened']);
            }

            if (array_key_exists('pr_closed', $data)) {
                $append['prOpen'] = count($data['pr_closed']);
            }

            if (array_key_exists('pr_merged', $data)) {
                $append['prCloset'] = count($data['pr_merged']);
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
