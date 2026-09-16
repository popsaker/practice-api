# Practice API

REST API для управления задачами на Laravel 13. Проект выполнен в Docker и предназначен для работы с задачами, статусами, дедлайнами и кэшированием.

## Стек

* PHP 8.5
* Laravel 13
* MySQL 8.4
* Nginx
* Docker
* Docker Compose
* Laravel File Cache

## Архитектура

Проект состоит из трёх основных контейнеров:

```text
Client / Postman
       ↓
    Nginx
       ↓
    Laravel
       ↓
    MySQL
```

Кэширование выполняется средствами Laravel с использованием файлового драйвера.

## Запуск проекта

### 1. Клонировать проект

```bash
git clone <URL_РЕПОЗИТОРИЯ>
cd practice-api
```

### 2. Создать `.env`

Скопировать `.env.example`:

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

Кэш:

```env
CACHE_STORE=file
```

### 3. Запустить Docker

```bash
docker compose up -d --build
```

Проверить состояние контейнеров:

```bash
docker compose ps
```

Все основные контейнеры должны быть запущены:

* `practice-api-app`
* `practice-api-nginx`
* `practice-api-mysql`

### 4. Выполнить миграции и заполнить тестовые данные

```bash
docker compose exec app php artisan migrate --seed
```

Команда создаёт таблицу `tasks` и три тестовые задачи.

### 5. Очистить кэш Laravel

```bash
docker compose exec app php artisan optimize:clear
```

После запуска API доступен по адресу:

```text
http://localhost:8080
```

## API

### Получение задачи

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
        "deadline": "2026-09-19T12:24:58.000000Z",
        "deadline_status": "on_track"
    },
    "cached": false
}
```

Поле `cached` показывает источник ответа:

* `false` — данные получены из базы данных;
* `true` — данные получены из кэша.

## Статус дедлайна

Для каждой задачи рассчитывается `deadline_status`.

### `overdue`

Дедлайн уже прошёл.

### `due_soon`

До дедлайна осталось не более 24 часов.

### `on_track`

До дедлайна осталось более 24 часов.

Статус дедлайна рассчитывается при выполнении GET-запроса на основе текущего времени.

В базе данных поле `deadline_status` также хранится в виде ENUM:

```text
overdue
due_soon
on_track
```

## Кэширование

Результат GET-запроса к задаче кэшируется на 60 секунд.

Ключ кэша:

```text
task:{id}
```

Например:

```text
task:1
```

Первый запрос:

```json
{
    "cached": false
}
```

Повторный запрос в течение 60 секунд:

```json
{
    "cached": true
}
```

После изменения статуса кэш соответствующей задачи удаляется.

## Изменение статуса

```http
PATCH /api/tasks/{id}/status
```

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

Допустимые значения:

```text
todo
in_progress
done
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

## Валидация

При передаче неизвестного статуса API возвращает HTTP `422`.

Например:

```json
{
    "status": "cancelled"
}
```

Ответ:

```json
{
    "message": "The selected status is invalid.",
    "errors": {
        "status": [
            "The selected status is invalid."
        ]
    }
}
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

### Неверный статус

Возвращает:

```text
422 Unprocessable Content
```

### Запрещённый переход статуса

Возвращает:

```text
422 Unprocessable Content
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

Для проекта создан `TaskSeeder`, который создаёт три тестовые задачи:

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

Коллекция содержит запросы для проверки:

* получения задачи;
* кэшированного получения задачи;
* изменения статуса;
* очистки кэша после изменения;
* запрещённого перехода `done → todo`;
* валидации неизвестного статуса;
* ошибки `404`;
* `due_soon`;
* `overdue`.

Базовый URL коллекции:

```text
http://localhost:8080
```

## Полезные Docker-команды

Запуск:

```bash
docker compose up -d
```

Пересборка:

```bash
docker compose up -d --build
```

Остановка:

```bash
docker compose down
```

Просмотр контейнеров:

```bash
docker compose ps
```

Логи Laravel:

```bash
docker compose logs app
```

Вход в контейнер Laravel:

```bash
docker compose exec app bash
```

Очистка кэша Laravel:

```bash
docker compose exec app php artisan optimize:clear
```

Просмотр маршрутов:

```bash
docker compose exec app php artisan route:list --path=api/tasks
```

## API-маршруты

```text
GET   /api/tasks/{id}
PATCH /api/tasks/{id}/status
```

Контроллер:

```text
app/Http/Controllers/Api/TaskController.php
```

Модель:

```text
app/Models/Task.php
```

Миграция:

```text
database/migrations/*_create_tasks_table.php
```

Seeder:

```text
database/seeders/TaskSeeder.php
```

## Переменные окружения

Файл `.env` не должен добавляться в Git, так как он содержит локальные настройки и пароли.

В репозитории используется:

```text
.env.example
```

## Проверка проекта

Основные требования задания реализованы:

* [x] Laravel запущен в Docker
* [x] Nginx используется как web-сервер
* [x] MySQL используется как база данных
* [x] REST API
* [x] `GET /api/tasks/{id}`
* [x] `deadline_status`
* [x] ENUM `deadline_status` в миграции
* [x] кэширование GET-запроса
* [x] TTL кэша 60 секунд
* [x] информация о попадании в кэш
* [x] `PATCH /api/tasks/{id}/status`
* [x] валидация статуса
* [x] проверка переходов статусов
* [x] очистка кэша после изменения статуса
* [x] обработка `404`
* [x] обработка `422`
* [x] Postman Collection
* [x] Seeder с тестовыми данными
* [x] README с инструкцией по запуску
