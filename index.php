<?php
declare (strict_types=1);

final class Run
{
    private $test;

    private $envFile = __DIR__ . '/env.json';
    private $dbFile = __DIR__ . '/db.json';

    private $db = [
        'common_count' => 0,
        'common_released' => 0,
        'app_count' => 0,
        'app_released' => 0,
    ];

    private $message = 'Нет.';

    private $botToken;

    private $groupChatId;
    private $privateChatId;

    private $emoji;

    public function __construct(bool $test)
    {
        if (file_exists($this->envFile) === false) {
            throw new RuntimeException('env.json not found');
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
        $this->send();

        if (!$this->test) {
            file_put_contents($this->dbFile, json_encode($this->db, JSON_PRETTY_PRINT));
        }
    }

    private function generateMessage(): void
    {
        // Статус всх пакетор
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

        if ($matches[1] === $matches[2]) {
            $this->message = 'ДА! ' . $this->emoji;
        }

        $this->message .= PHP_EOL . 'Прогресс: ' . $appReleased . '/' . $appCount . ' (' . round($appReleased / $appCount * 100) . '%)';

        if (
            $this->db['app_count'] !== $appCount ||
            $this->db['app_released'] !== $appReleased
        ) {
            $this->message .= ' ' . $this->emoji;
        }


        $this->message .= PHP_EOL . 'Прогресс всех пакетов: ' . $matches[1] . '/' . $matches[2] . ' (' . round($matches[1] / $matches[2] * 100) . '%)';

        if (
            $this->db['common_released'] !== (int)$matches[1] ||
            $this->db['common_count'] !== (int)$matches[2]
        ) {
            $this->message .= ' ' . $this->emoji;
        }

        $this->db['common_released'] = (int)$matches[1];
        $this->db['common_count'] = (int)$matches[2];
        $this->db['app_count'] = $appCount;
        $this->db['app_released'] = $appReleased;

        $yesterday = date('Y-m-d', time() - (60 * 60 * 24));

        if (array_key_exists($yesterday, $this->db) === false) {
            return;
        }

        // Статус PR и Issue
        $issueOpened = count($this->db[$yesterday]['issue_opened']);
        $issueClosed = count($this->db[$yesterday]['issue_closed']);
        $prOpened = count($this->db[$yesterday]['pr_opened']);
        $prMerged = count($this->db[$yesterday]['pr_merged']);
        $prClosed = count($this->db[$yesterday]['pr_closed']);

        $this->message .= PHP_EOL . 'Issue:';

        if ($issueOpened !== 0 || $issueClosed !== 0) {
            if ($issueOpened !== 0) {
                $this->message .= ' ' . $issueOpened . ' открыто';
            }

            if ($issueOpened !== 0 && $issueClosed !== 0) {
                $this->message .= ',';
            }

            if ($issueClosed !== 0) {
                $this->message .= ' ' . $issueClosed . ' закрыто';
            }

            $this->message .= '.';
        } else {
            $this->message .= ' активности не было.';
        }

        $this->message .= PHP_EOL . 'PR:';

        if ($prOpened !== 0 || $prMerged !== 0 || $prClosed !== 0) {
            if ($prOpened !== 0) {
                $this->message .= ' ' . $prOpened . ' открыто';
            }

            if ($prOpened !== 0 && $prMerged !== 0) {
                $this->message .= ',';
            }

            if ($prMerged !== 0) {
                $this->message .= ' ' . $prMerged . ' принято';
            }

            if ($prMerged !== 0 && $prClosed !== 0) {
                $this->message .= ',';
            }

            if ($prClosed !== 0) {
                $this->message .= ' ' . $prClosed . ' закрыто';
            }

            $this->message .= '.';
        } else {
            $this->message .= ' активности не было.';
        }
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

(new Run(array_key_exists(1, $argv)))->run();
