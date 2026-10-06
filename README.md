# Practice API

REST API для управления задачами на Laravel 13.

Проект запускается в Docker и использует MySQL, Redis, Redis Queue, Laravel Scheduler и Laravel Sanctum.

## Стек

- PHP 8.5
- Laravel 13
- MySQL 8.4
- Nginx
- Docker / Docker Compose
- Redis 7
- Laravel Sanctum
- PHPUnit

## Архитектура

```text
Client / Postman
       |
       v
    Nginx :8080
       |
       v
    Laravel
     /   \
    v     v
 MySQL   Redis
          |
          v
      Queue Worker

Scheduler
    |
    v
app:check-overdue-tasks
    |
    v
TaskBecameOverdueJob
    |
    v
MySQL: task_overdue_logs
```

Сервисы Docker:

| Сервис | Назначение |
|---|---|
| `app` | PHP-FPM + Laravel |
| `nginx` | Web-сервер |
| `mysql` | MySQL 8.4 |
| `redis` | Redis для кэша и очереди |
| `queue` | Redis Queue Worker |
| `scheduler` | Laravel Scheduler |

Nginx доступен на `http://localhost:8080`.
MySQL доступен с хоста на `localhost:3307`.
Redis внутри Docker-сети работает на `6379`.

## Запуск с чистого клона

Инструкция рассчитана на машину, где установлен Docker Desktop с Docker Compose.

### 1. Клонирование

```bash
git clone <URL_РЕПОЗИТОРИЯ>
cd practice-api
```

### 2. Создать `.env`

```bash
cp .env.example .env
```

В `.env.example` уже указаны значения для Docker. В частности:

```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=practice
DB_USERNAME=practice
DB_PASSWORD=practice_password

CACHE_STORE=redis
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
```

**Не меняйте `REDIS_HOST` на `127.0.0.1`: внутри контейнеров Redis доступен по имени сервиса `redis`.**

### 3. Собрать Docker-образ

```bash
docker compose build
```

Composer-зависимости устанавливаются автоматически во время сборки Docker-образа. Отдельно выполнять `composer install` не требуется.

### 4. Сгенерировать `APP_KEY`

После создания `.env` выполните:

```bash
docker compose run --rm app php artisan key:generate --force
```

Эта команда выполняется до запуска постоянных контейнеров, поэтому queue и scheduler сразу получают корректный `.env` и установленный Laravel.

### 5. Запустить Docker

```bash
docker compose up -d
```

Проверить контейнеры:

```bash
docker compose ps
```

Должны работать:

```text
practice-api-app
practice-api-nginx
practice-api-mysql
practice-api-redis
practice-api-queue
practice-api-scheduler
```

### 6. Выполнить миграции и сидер

```bash
docker compose exec app php artisan migrate --seed
```

После этого база данных и тестовые задачи готовы.

### 7. Проверить API

```text
http://localhost:8080
```

Например:

```http
GET http://localhost:8080/api/tasks/1
```

### 8. Быстрая проверка Redis

```bash
docker compose exec redis redis-cli ping
```

Ожидается:

```text
PONG
```

## Права на storage

`Dockerfile` подключает `docker/entrypoint.sh` как настоящий `ENTRYPOINT`.

При запуске контейнера entrypoint:

1. создаёт необходимые каталоги Laravel;
2. назначает `www-data` владельцем `storage`, `bootstrap/cache` и `vendor`;
3. выдаёт группе права на запись;
4. запускает PHP-FPM, queue worker или scheduler от имени `www-data`.

Поэтому queue и scheduler не создают root-owned Laravel-логи.

## API

### Получение одной задачи

```http
GET /api/tasks/{id}
```

Ответ содержит:

```json
{
    "task": {
        "id": 1,
        "title": "Тестовая задача",
        "description": "Проверка API",
        "status": "todo",
        "deadline": "2026-10-01T12:00:00.000000Z",
        "deadline_status": "on_track",
        "created_at": "2026-09-16T12:00:00.000000Z",
        "updated_at": "2026-09-16T12:00:00.000000Z"
    },
    "cached": false
}
```

Повторный запрос в течение 60 секунд возвращает `cached: true`.

### Список задач

```http
GET /api/tasks
```

Фильтры:

```text
status=todo|in_progress|done
deadline_status=overdue|due_soon|on_track
per_page=1..100
```

Пример:

```http
GET /api/tasks?status=todo&deadline_status=overdue&per_page=10
```

### Создание задачи

```http
POST /api/tasks
```

Тело:

```json
{
    "title": "Новая задача",
    "description": "Описание",
    "deadline": "2026-12-01 12:00:00"
}
```

`title` обязателен. `deadline` не может быть в прошлом.

### Авторизация

```http
POST /api/login
```

```json
{
    "email": "test@example.com",
    "password": "password123"
}
```

Endpoint ограничен пятью попытками в минуту.

### Изменение статуса

```http
PATCH /api/tasks/{id}/status
```

Требуется:

```text
Authorization: Bearer <TOKEN>
```

Тело:

```json
{
    "status": "in_progress"
}
```

Разрешённые переходы:

```text
todo        -> todo, in_progress, done
in_progress -> in_progress, todo, done
done        -> done, in_progress
```

`done -> todo` возвращает `422`.

После успешного изменения статуса кэш задачи удаляется.

## Deadline status

`deadline_status` хранится в MySQL как ENUM:

```text
overdue
due_soon
on_track
```

Расчёт:

- `overdue` — дедлайн уже прошёл;
- `due_soon` — до дедлайна не более 24 часов;
- `on_track` — до дедлайна более 24 часов.

При фильтрации API используется фактический `deadline`, поэтому просроченная задача определяется по времени, даже если сохранённое поле `deadline_status` устарело.

## Redis-кэш

`GET /api/tasks/{id}` кэшируется на 60 секунд.

Ключ:

```text
task:{id}
```

В Redis сохраняется **готовый массив, возвращаемый `TaskResource`**. При попадании в кэш модель Eloquent заново не собирается.

Проверка:

```bash
docker compose exec redis redis-cli ping
```

## Просроченные задачи, Queue и Scheduler

Artisan-команда:

```text
app:check-overdue-tasks
```

Ручной запуск:

```bash
docker compose exec app php artisan app:check-overdue-tasks
```

Команда ищет задачи по факту:

```text
deadline < now()
AND task_overdue_logs для задачи ещё нет
```

Она **не использует `deadline_status != overdue` как условие обнаружения**.

Для найденной задачи команда отправляет:

```text
TaskBecameOverdueJob
```

в Redis Queue.

Статус `deadline_status = overdue` и запись в `task_overdue_logs` выполняются внутри Job в одной транзакции. Это важно для отказоустойчивости:

```text
Redis недоступен
    ↓
dispatch() не выполнен
    ↓
задача не помечается overdue
    ↓
следующий запуск Scheduler попробует снова
```

Если Job уже попала в Redis, но worker временно недоступен, она остаётся в очереди и обрабатывается worker после восстановления.

Job идемпотентна: `task_overdue_logs.task_id` уникален, а повторная обработка не создаёт дубль.

Пример успешного сообщения команды:

```text
Задача #4: Job отправлен в Redis.
```

При ошибке отправки команда выводит ошибку и завершается с ненулевым кодом. В таком случае статус задачи не меняется, поэтому следующий запуск может повторить отправку.

### Scheduler

В `routes/console.php`:

```php
Schedule::command('app:check-overdue-tasks')->everyMinute();
```

Контейнер `scheduler` запускает:

```bash
php artisan schedule:work
```

Проверка расписания:

```bash
docker compose exec app php artisan schedule:list
```

### Queue Worker

Контейнер `queue` запускает:

```bash
php artisan queue:work redis --sleep=1 --tries=3
```

Проверить его логи:

```bash
docker compose logs queue
```

## `task_overdue_logs`

Таблица фиксирует обработку просроченной задачи.

`task_id` имеет внешний ключ на `tasks.id` и уникальный индекс, поэтому одна задача не создаёт несколько одинаковых записей.

## Тесты

Запуск:

```bash
docker compose exec app php artisan test
```

Тесты покрывают:

- GET одной задачи;
- Redis-кэш и TTL-логику приложения;
- 404;
- создание задачи;
- валидацию `title`;
- запрет дедлайна в прошлом;
- PATCH без токена;
- PATCH с токеном;
- переходы статусов;
- очистку кэша;
- Sanctum login;
- rate limit login;
- фильтрацию и пагинацию;
- выполнение `TaskBecameOverdueJob`;
- изменение `deadline_status` Job;
- создание `task_overdue_logs` Job;
- постановку Job в очередь;
- работу `app:check-overdue-tasks`;
- поиск просроченной задачи независимо от сохранённого `deadline_status`;
- отсутствие повторной Job при существующем `task_overdue_logs`.

## Полезные команды

### Пересборка

```bash
docker compose up -d --build
```

### Остановка

```bash
docker compose down
```

### Остановка с удалением MySQL и Docker volumes

```bash
docker compose down -v
```

### Статус

```bash
docker compose ps
```

### Laravel logs

```bash
docker compose logs app
```

### Queue logs

```bash
docker compose logs queue
```

### Scheduler logs

```bash
docker compose logs scheduler
```

### Redis logs

```bash
docker compose logs redis
```

### Очистка Laravel-кэша

```bash
docker compose exec app php artisan optimize:clear
```

### API routes

```bash
docker compose exec app php artisan route:list --path=api
```

### Scheduler

```bash
docker compose exec app php artisan schedule:list
```

### Redis

```bash
docker compose exec redis redis-cli ping
```

### Tests

```bash
docker compose exec app php artisan test
```

## Postman

Готовая коллекция находится в:

```text
Practice_API.postman_collection.json
```

## Основные файлы

```text
Dockerfile
compose.yaml
docker/entrypoint.sh
.env.example
README.md

app/Console/Commands/CheckOverdueTasks.php
app/Jobs/TaskBecameOverdueJob.php
app/Http/Controllers/Api/TaskController.php
app/Http/Resources/TaskResource.php
app/Models/Task.php
app/Models/TaskOverdueLog.php

routes/api.php
routes/console.php

database/seeders/TaskSeeder.php
database/migrations/*_create_tasks_table.php
database/migrations/*_create_task_overdue_logs_table.php

tests/Feature/TaskApiTest.php
Practice_API.postman_collection.json
```
