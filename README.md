# Practice API

REST API для управления задачами на **Laravel 13**.

Проект выполнен в Docker и предназначен для работы с задачами, статусами, дедлайнами, кэшированием, фильтрацией, пагинацией, авторизацией через Laravel Sanctum, Redis Queue и планировщиком задач Laravel.

## Стек

* PHP 8.5
* Laravel 13
* MySQL 8.4
* Nginx
* Docker
* Docker Compose
* Redis 7
* Laravel Sanctum
* PHPUnit

## Архитектура

Проект состоит из следующих сервисов:

```text
Client / Postman
       |
       v
    Nginx
       |
       v
    Laravel
     /   \
    v     v
 MySQL   Redis
          |
          v
      Queue Worker

Laravel Scheduler
       |
       v
Artisan Command
       |
       v
Redis Queue
       |
       v
      Job
       |
       v
    MySQL
```

### Docker-сервисы

| Сервис      | Назначение            |
| ----------- | --------------------- |
| `app`       | Laravel + PHP-FPM     |
| `nginx`     | Web-сервер            |
| `mysql`     | База данных MySQL 8.4 |
| `redis`     | Кэш и очередь         |
| `queue`     | Laravel Queue Worker  |
| `scheduler` | Laravel Scheduler     |

Nginx доступен с хоста на порту `8080`.

MySQL доступен с хоста на порту `3307`.

Redis используется внутри Docker-сети на порту `6379`.

## Запуск проекта

### 1. Клонировать проект

```bash
git clone <URL_РЕПОЗИТОРИЯ>

cd practice-api
```

### 2. Создать `.env`

Скопировать файл примера:

```bash
cp .env.example .env
```

Основные настройки базы данных:

```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=practice
DB_USERNAME=practice
DB_PASSWORD=practice_password
```

Настройки очереди:

```env
QUEUE_CONNECTION=redis
```

Настройки кэша:

```env
CACHE_STORE=redis
```

Настройки Redis:

```env
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### 3. Запустить Docker

```bash
docker compose up -d --build
```

Проверить состояние контейнеров:

```bash
docker compose ps
```

Должны быть запущены:

```text
practice-api-app
practice-api-nginx
practice-api-mysql
practice-api-redis
practice-api-queue
practice-api-scheduler
```

MySQL и Redis должны иметь статус `healthy`.

### 4. Выполнить миграции и заполнить тестовые данные

```bash
docker compose exec app php artisan migrate --seed
```

Команда создаёт таблицы базы данных и тестовые задачи.

### 5. Проверить API

API доступен по адресу:

```text
http://localhost:8080
```

Например:

```http
GET http://localhost:8080/api/tasks/1
```

## API

### 1. Получение одной задачи

```http
GET /api/tasks/{id}
```

Пример:

```http
GET http://localhost:8080/api/tasks/1
```

Пример ответа:

```json
{
    "task": {
        "id": 1,
        "title": "Тестовая задача",
        "description": "Проверка API",
        "status": "todo",
        "deadline": "2026-10-01T12:00:00.000000Z",
        "deadline_status": "on_track",
        "created_at": "2026-09-28T12:00:00.000000Z",
        "updated_at": "2026-09-28T12:00:00.000000Z"
    },
    "cached": false
}
```

Поле `cached` показывает источник ответа:

* `false` — данные получены из базы данных;
* `true` — данные получены из кэша Redis.

### 2. Получение списка задач

```http
GET /api/tasks
```

Endpoint поддерживает:

* пагинацию;
* фильтр по `status`;
* фильтр по `deadline_status`;
* параметр `per_page`.

Пример:

```http
GET http://localhost:8080/api/tasks
```

Количество элементов на странице:

```http
GET http://localhost:8080/api/tasks?per_page=5
```

Фильтр по статусу:

```http
GET http://localhost:8080/api/tasks?status=todo
```

Допустимые значения:

```text
todo
in_progress
done
```

Фильтр по статусу дедлайна:

```http
GET http://localhost:8080/api/tasks?deadline_status=overdue
```

Допустимые значения:

```text
overdue
due_soon
on_track
```

Фильтры можно комбинировать:

```http
GET http://localhost:8080/api/tasks?status=todo&deadline_status=on_track&per_page=5
```

При передаче недопустимого значения API возвращает HTTP `422`.

### 3. Создание задачи

```http
POST /api/tasks
```

Пример:

```http
POST http://localhost:8080/api/tasks
```

Тело запроса:

```json
{
    "title": "Новая задача",
    "description": "Описание задачи",
    "status": "todo",
    "deadline": "2026-10-01 12:00:00"
}
```

Правила:

* `title` — обязательное поле;
* `description` — необязательное поле;
* `status` — необязательное поле;
* `deadline` — обязательное поле;
* `deadline` не может находиться в прошлом.

Если `status` не передан, используется:

```text
todo
```

Допустимые значения `status`:

```text
todo
in_progress
done
```

При успешном создании API возвращает HTTP `201 Created`.

Пример:

```json
{
    "message": "Задача успешно создана",
    "task": {
        "id": 4,
        "title": "Новая задача",
        "description": "Описание задачи",
        "status": "todo",
        "deadline": "2026-10-01T12:00:00.000000Z",
        "deadline_status": "on_track"
    }
}
```

## Статус дедлайна

Для каждой задачи рассчитывается `deadline_status`.

### `overdue`

Дедлайн уже прошёл.

### `due_soon`

До дедлайна осталось не более 24 часов.

### `on_track`

До дедлайна осталось более 24 часов.

Статус рассчитывается на основе текущего времени.

В базе данных поле `deadline_status` хранится в виде ENUM:

```text
overdue
due_soon
on_track
```

## Кэширование Redis

Результат `GET /api/tasks/{id}` кэшируется на 60 секунд.

Для кэширования используется Redis.

Ключ кэша:

```text
task:{id}
```

Например:

```text
task:1
```

Первый запрос получает данные из базы:

```json
{
    "cached": false
}
```

Повторный запрос в течение 60 секунд получает данные из Redis:

```json
{
    "cached": true
}
```

После успешного изменения статуса соответствующий кэш удаляется.

Проверить работу Redis можно командой:

```bash
docker compose exec redis redis-cli ping
```

Ожидаемый результат:

```text
PONG
```

## Изменение статуса

```http
PATCH /api/tasks/{id}/status
```

Endpoint защищён Laravel Sanctum.

Без токена запрос возвращает HTTP `401`.

Пример:

```http
PATCH http://localhost:8080/api/tasks/1/status
```

Тело запроса:

```json
{
    "status": "in_progress"
}
```

Заголовок авторизации:

```text
Authorization: Bearer <TOKEN>
```

После успешного изменения статуса:

1. изменяется статус задачи;
2. пересчитывается `deadline_status`;
3. данные сохраняются в MySQL;
4. кэш задачи удаляется.

## Авторизация

### Получение токена

```http
POST /api/login
```

Пример:

```http
POST http://localhost:8080/api/login
```

Тело запроса:

```json
{
    "email": "test@example.com",
    "password": "password123"
}
```

При успешной авторизации API возвращает Sanctum token:

```json
{
    "message": "Успешная авторизация",
    "token": "<TOKEN>",
    "user": {
        "id": 1,
        "name": "Test User",
        "email": "test@example.com"
    }
}
```

Полученный токен используется для защищённого PATCH-запроса:

```text
Authorization: Bearer <TOKEN>
```

## Ограничение попыток входа

Endpoint `/api/login` защищён ограничителем запросов:

```text
5 попыток в минуту
```

После превышения лимита API возвращает:

```text
429 Too Many Requests
```

Проверка реализована middleware:

```php
->middleware('throttle:5,1');
```

## Переходы статусов

Разрешены следующие переходы:

```text
todo
 ├── todo
 ├── in_progress
 └── done

in_progress
 ├── in_progress
 ├── todo
 └── done

done
 ├── done
 └── in_progress
```

Переход:

```text
done → todo
```

запрещён.

При попытке выполнить запрещённый переход API возвращает HTTP `422`.

Пример:

```json
{
    "message": "Недопустимый переход статуса: done → todo"
}
```

## Автоматическая обработка просроченных задач

Для обработки задач, срок которых истёк, реализована Artisan-команда:

```text
app:check-overdue-tasks
```

Запуск вручную:

```bash
docker compose exec app php artisan app:check-overdue-tasks
```

Команда:

1. ищет задачи с прошедшим дедлайном;
2. проверяет, что они ещё не имеют `deadline_status = overdue`;
3. изменяет `deadline_status` на `overdue`;
4. отправляет `TaskBecameOverdueJob` в Redis Queue.

Пример сообщения:

```text
Задача #4 стала просроченной. Job отправлен в Redis.
```

### Redis Queue

Очередь использует:

```env
QUEUE_CONNECTION=redis
```

Отдельный контейнер выполняет Queue Worker:

```text
practice-api-queue
```

Команда Worker:

```bash
php artisan queue:work redis --sleep=1 --tries=3
```

Job:

```text
App\Jobs\TaskBecameOverdueJob
```

Job создаёт запись в таблице:

```text
task_overdue_logs
```

Таким образом, цепочка обработки выглядит следующим образом:

```text
Scheduler
    ↓
app:check-overdue-tasks
    ↓
Redis Queue
    ↓
TaskBecameOverdueJob
    ↓
task_overdue_logs
```

## Планировщик Laravel

Команда проверки просроченных задач запускается автоматически каждую минуту.

Настройка находится в:

```text
routes/console.php
```

Используется:

```php
Schedule::command('app:check-overdue-tasks')->everyMinute();
```

Отдельный контейнер:

```text
practice-api-scheduler
```

запускает:

```bash
php artisan schedule:work
```

Проверить зарегистрированные задачи можно:

```bash
docker compose exec app php artisan schedule:list
```

Ожидаемая задача:

```text
* * * * *  php artisan app:check-overdue-tasks
```

## Таблица `task_overdue_logs`

Для фиксации перехода задачи в состояние `overdue` создана таблица:

```text
task_overdue_logs
```

Структура:

| Поле         | Тип       | Описание             |
| ------------ | --------- | -------------------- |
| `id`         | BIGINT    | Идентификатор записи |
| `task_id`    | BIGINT    | Идентификатор задачи |
| `created_at` | TIMESTAMP | Дата создания        |
| `updated_at` | TIMESTAMP | Дата изменения       |

Для `task_id` используется внешний ключ на таблицу `tasks`.

Также установлен `unique` на `task_id`, поэтому одна задача не создаёт несколько одинаковых записей о переходе в `overdue`.

## TaskResource

Для формирования API-ответов используется Laravel API Resource:

```text
app/Http/Resources/TaskResource.php
```

`TaskResource` используется для:

* `GET /api/tasks`;
* `GET /api/tasks/{id}`;
* `POST /api/tasks`;
* `PATCH /api/tasks/{id}/status`.

Resource централизует формат представления задачи в API.

## Валидация

API выполняет валидацию входных данных.

### Неверный статус

Например:

```json
{
    "status": "cancelled"
}
```

API возвращает:

```text
422 Unprocessable Content
```

### Отсутствует название задачи

При создании задачи без `title` API возвращает:

```text
422 Unprocessable Content
```

### Дедлайн в прошлом

При попытке создать задачу с прошедшим дедлайном API возвращает:

```text
422 Unprocessable Content
```

## Обработка ошибок

### Задача не найдена

```http
GET /api/tasks/999
```

Возвращает:

```text
404 Not Found
```

### Неавторизованный PATCH

```http
PATCH /api/tasks/1/status
```

без заголовка:

```text
Authorization: Bearer <TOKEN>
```

возвращает:

```text
401 Unauthorized
```

### Неверные входные данные

Возвращается:

```text
422 Unprocessable Content
```

### Превышение лимита входа

После более чем пяти попыток авторизации в течение минуты:

```text
429 Too Many Requests
```

## Структура таблицы `tasks`

| Поле              | Тип       | Описание        |
| ----------------- | --------- | --------------- |
| `id`              | BIGINT    | Идентификатор   |
| `title`           | VARCHAR   | Название задачи |
| `description`     | TEXT      | Описание        |
| `status`          | ENUM      | Текущий статус  |
| `deadline`        | DATETIME  | Дедлайн         |
| `deadline_status` | ENUM      | Статус дедлайна |
| `created_at`      | TIMESTAMP | Дата создания   |
| `updated_at`      | TIMESTAMP | Дата изменения  |

### Значения `status`

```text
todo
in_progress
done
```

### Значения `deadline_status`

```text
overdue
due_soon
on_track
```

## Seeder

Для проекта создан `TaskSeeder`, который создаёт тестовые задачи:

| ID | Название        | Дедлайн       |
| -- | --------------- | ------------- |
| 1  | Тестовая задача | через 3 дня   |
| 2  | Тест due_soon   | через 5 часов |
| 3  | Тест overdue    | вчера         |

Запуск:

```bash
docker compose exec app php artisan db:seed
```

При полной установке:

```bash
docker compose exec app php artisan migrate --seed
```

## Postman

В корне проекта находится коллекция:

```text
Practice_API.postman_collection.json
```

Импортировать её можно через:

```text
Postman → Import → Practice_API.postman_collection.json
```

Базовый URL:

```text
http://localhost:8080
```

Коллекция предназначена для проверки:

* получения задачи;
* кэшированного получения задачи;
* получения списка задач;
* фильтрации задач;
* пагинации;
* создания задачи;
* авторизации;
* ограничения попыток входа;
* изменения статуса;
* очистки кэша после изменения;
* запрещённого перехода `done → todo`;
* валидации;
* ошибки `404`;
* ошибки `401`;
* ошибки `429`;
* статусов `due_soon` и `overdue`.

## Автоматические тесты

Для проекта написаны Feature-тесты API.

Запуск всех тестов:

```bash
docker compose exec app php artisan test
```

Тесты проверяют:

* получение задачи;
* использование Redis-кэша;
* ошибку `404`;
* создание задачи;
* валидацию `title`;
* валидацию прошедшего `deadline`;
* доступ к PATCH без токена;
* доступ к PATCH с токеном;
* запрещённый переход `done → todo`;
* очистку кэша после изменения статуса;
* получение Sanctum-токена;
* ограничение попыток входа;
* выполнение `TaskBecameOverdueJob`;
* постановку Job в очередь;
* фильтрацию задач;
* пагинацию.

Текущий результат:

```text
Tests: 19 passed (64 assertions)
```

## API-маршруты

```text
POST  /api/login
GET   /api/tasks
POST  /api/tasks
GET   /api/tasks/{id}
PATCH /api/tasks/{id}/status
```

`PATCH /api/tasks/{id}/status` требует авторизацию через Sanctum.

`POST /api/login` ограничен пятью попытками в минуту.

## Основные файлы

### Контроллер задач

```text
app/Http/Controllers/Api/TaskController.php
```

### Контроллер авторизации

```text
app/Http/Controllers/Api/AuthController.php
```

### API Resource

```text
app/Http/Resources/TaskResource.php
```

### Модель задачи

```text
app/Models/Task.php
```

### Модель пользователя

```text
app/Models/User.php
```

### Модель журнала просрочек

```text
app/Models/TaskOverdueLog.php
```

### Job

```text
app/Jobs/TaskBecameOverdueJob.php
```

### Artisan-команда

```text
app/Console/Commands/CheckOverdueTasks.php
```

### Маршруты API

```text
routes/api.php
```

### Планировщик

```text
routes/console.php
```

### Миграция задач

```text
database/migrations/*_create_tasks_table.php
```

### Миграция журнала просрочек

```text
database/migrations/2026_09_28_161530_create_task_overdue_logs_table.php
```

### Seeder

```text
database/seeders/TaskSeeder.php
```

### Feature-тесты

```text
tests/Feature/TaskApiTest.php
```

### Docker-конфигурация

```text
Dockerfile
compose.yaml
```

### Nginx

```text
nginx/default.conf
```

### Postman

```text
Practice_API.postman_collection.json
```

## Переменные окружения

Файл `.env` не должен добавляться в Git, так как он содержит локальные настройки и пароли.

В репозитории используется:

```text
.env.example
```

Основные переменные:

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

## Полезные Docker-команды

### Запуск

```bash
docker compose up -d
```

### Запуск с пересборкой

```bash
docker compose up -d --build
```

### Остановка

```bash
docker compose down
```

### Остановка с удалением томов

```bash
docker compose down -v
```

### Просмотр контейнеров

```bash
docker compose ps
```

### Логи Laravel

```bash
docker compose logs app
```

### Логи Queue Worker

```bash
docker compose logs queue
```

### Логи Scheduler

```bash
docker compose logs scheduler
```

### Логи Redis

```bash
docker compose logs redis
```

### Вход в контейнер Laravel

```bash
docker compose exec app bash
```

### Очистка кэша Laravel

```bash
docker compose exec app php artisan optimize:clear
```

### Просмотр маршрутов

```bash
docker compose exec app php artisan route:list --path=api
```

### Просмотр задач Scheduler

```bash
docker compose exec app php artisan schedule:list
```

### Ручной запуск проверки просроченных задач

```bash
docker compose exec app php artisan app:check-overdue-tasks
```

### Проверка Redis

```bash
docker compose exec redis redis-cli ping
```

### Запуск тестов

```bash
docker compose exec app php artisan test
```

## Проверка проекта

Основные требования задания реализованы:

* [x] Laravel запущен в Docker
* [x] Nginx используется как web-сервер
* [x] MySQL используется как база данных
* [x] Redis используется для кэширования
* [x] Redis используется как Queue Driver
* [x] Queue Worker работает в отдельном контейнере
* [x] Laravel Scheduler работает в отдельном контейнере
* [x] REST API
* [x] `GET /api/tasks/{id}`
* [x] `GET /api/tasks`
* [x] фильтрация по `status`
* [x] фильтрация по `deadline_status`
* [x] пагинация
* [x] `POST /api/tasks`
* [x] валидация создания задачи
* [x] запрет дедлайна в прошлом
* [x] `deadline_status`
* [x] ENUM `deadline_status` в миграции
* [x] кэширование GET-запроса
* [x] TTL кэша 60 секунд
* [x] информация о попадании в кэш
* [x] очистка кэша после изменения статуса
* [x] `PATCH /api/tasks/{id}/status`
* [x] валидация статуса
* [x] проверка переходов статусов
* [x] запрет `done → todo`
* [x] Laravel Sanctum
* [x] `POST /api/login`
* [x] защита PATCH через Sanctum
* [x] обработка `401`
* [x] ограничение входа до 5 попыток в минуту
* [x] обработка `404`
* [x] обработка `422`
* [x] обработка `429`
* [x] `TaskResource`
* [x] Artisan-команда `app:check-overdue-tasks`
* [x] автоматическая проверка просроченных задач
* [x] `TaskBecameOverdueJob`
* [x] отправка Job в Redis Queue
* [x] обработка Job Queue Worker
* [x] запись результата Job в `task_overdue_logs`
* [x] автоматический запуск команды через Scheduler
* [x] Feature-тесты
* [x] Postman Collection
* [x] Seeder с тестовыми данными
* [x] README с инструкцией по запуску
