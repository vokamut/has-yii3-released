<p align="center">
<img src="https://github.com/vokamut/has-yii3-released/actions/workflows/deploy.yml/badge.svg" alt="GitHub Actions Deploy">
</p>

# Бот для проверки статуса разработки Yii 3 [@has_yii3_released](https://t.me/has_yii3_released)

Онлайн прогресс: https://hasyii3released.vokamut.ru

## Установка

- Скопировать `env.example.json` в `env.json`, прописать там ключ бота и ID чатов.

### Добавить в cron
```
30 09 * * * php test /path/to/index.php
10 10 * * * php /path/to/index.php
00 * * * * php /path/to/events.php
```
