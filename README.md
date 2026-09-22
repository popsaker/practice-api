# Practice API

REST API для управления задачами на Laravel 13.

Проект выполнен в Docker и предназначен для работы с задачами, статусами, дедлайнами, кэшированием, фильтрацией, пагинацией и авторизацией через Laravel Sanctum.

## Стек

* PHP 8.5
* Laravel 13
* MySQL 8.4
* Nginx
* Docker
* Docker Compose
* Laravel File Cache
* Laravel Sanctum
* PHPUnit

Redis в проекте не используется. Для кэширования применяется файловый драйвер Laravel, что допускается условиями задания.

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

Должны быть запущены:

* `practice-api-app`
* `practice-api-nginx`
* `practice-api-mysql`

MySQL должен иметь статус `healthy`.

### 4. Выполнить миграции и заполнить тестовые данные

```bash
docker compose exec app php artisan migrate --seed
```

Команда создаёт таблицы базы данных и три тестовые задачи.

### 5. Проверить API

API доступен по адресу:

```text
http://localhost:8080
```

Например:

```http
GET http://localhost:8080/api/tasks/1
```

### Права на `storage`

При запуске контейнера права Laravel автоматически настраиваются через Docker entrypoint.

Дополнительный ручной `chmod` для:

```text
storage/
bootstrap/cache/
```

не требуется.

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
        "deadline": "2026-09-19T12:24:58.000000Z",
        "deadline_status": "overdue"
    },
    "cached": false
}
```

Поле `cached` показывает источник ответа:

* `false` — данные получены из базы данных;
* `true` — данные получены из кэша.

---

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

---

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

Поле `title` обязательно.

Поле `deadline` обязательно и не может находиться в прошлом.

Поле `status` необязательно. Если оно не передано, используется:

```text
todo
```

Допустимые значения:

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

---

## Статус дедлайна

Для каждой задачи рассчитывается `deadline_status`.

### `overdue`

Дедлайн уже прошёл.

### `due_soon`

До дедлайна осталось не более 24 часов.

### `on_track`

До дедлайна осталось более 24 часов.

Статус дедлайна рассчитывается на основе текущего времени.

В базе данных поле `deadline_status` хранится в виде ENUM:

```text
overdue
due_soon
on_track
```

## Кэширование

Результат GET-запроса конкретной задачи кэшируется на 60 секунд.

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

Повторный запрос в течение 60 секунд получает данные из кэша:

```json
{
    "cached": true
}
```

После успешного изменения статуса соответствующий кэш удаляется.

## Изменение статуса

```http
PATCH /api/tasks/{id}/status
```

Данный endpoint защищён Laravel Sanctum.

Без токена запрос возвращает:

```text
401 Unauthenticated
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

Заголовок авторизации:

```text
Authorization: Bearer <TOKEN>
```

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

---

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

После успешного изменения статуса кэш задачи удаляется.

---

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

---

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
401 Unauthenticated
```

### Неверные входные данные

Возвращается:

```text
422 Unprocessable Content
```

### Запрещённый переход статуса

Возвращается:

```text
422 Unprocessable Content
```

---

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
* изменения статуса;
* очистки кэша после изменения;
* запрещённого перехода `done → todo`;
* валидации;
* ошибки `404`;
* ошибки `401`;
* статусов `due_soon` и `overdue`.

## Автоматические тесты

Для проекта написаны Feature-тесты API.

Запуск всех тестов:

```bash
docker compose exec app php artisan test
```

Тесты проверяют:

* получение задачи;
* использование кэша;
* ошибку `404`;
* создание задачи;
* валидацию `title`;
* валидацию прошедшего `deadline`;
* доступ к PATCH без токена;
* доступ к PATCH с токеном;
* запрещённый переход `done → todo`;
* очистку кэша после изменения статуса;
* получение Sanctum-токена.

Текущий результат:

```text
Tests: 12 passed (43 assertions)
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

## Основные файлы

Контроллер:

```text
app/Http/Controllers/Api/TaskController.php
```

Контроллер авторизации:

```text
app/Http/Controllers/Api/AuthController.php
```

Модель задачи:

```text
app/Models/Task.php
```

Модель пользователя:

```text
app/Models/User.php
```

Маршруты:

```text
routes/api.php
```

Миграция задач:

```text
database/migrations/*_create_tasks_table.php
```

Seeder:

```text
database/seeders/TaskSeeder.php
```

Feature-тесты:

```text
tests/Feature/TaskApiTest.php
```

Docker entrypoint:

```text
docker/entrypoint.sh
```

Docker-конфигурация:

```text
Dockerfile
compose.yaml
```

Nginx:

```text
nginx/default.conf
```

Postman:

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

CACHE_STORE=file
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

### Просмотр контейнеров

```bash
docker compose ps
```

### Логи Laravel

```bash
docker compose logs app
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

### Запуск тестов

```bash
docker compose exec app php artisan test
```

## Проверка проекта

Основные требования задания реализованы:

* [x] Laravel запущен в Docker
* [x] Nginx используется как web-сервер
* [x] MySQL используется как база данных
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
* [x] `PATCH /api/tasks/{id}/status`
* [x] валидация статуса
* [x] проверка переходов статусов
* [x] запрет `done → todo`
* [x] очистка кэша после изменения статуса
* [x] Laravel Sanctum
* [x] `POST /api/login`
* [x] защита PATCH через Sanctum
* [x] обработка `401`
* [x] обработка `404`
* [x] обработка `422`
* [x] автоматические Feature-тесты
* [x] Postman Collection
* [x] Seeder с тестовыми данными
* [x] Docker entrypoint для автоматической настройки прав
* [x] отсутствие необходимости ручного `chmod`
* [x] README с инструкцией по запуску
