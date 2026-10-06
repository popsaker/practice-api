<?php

namespace Tests\Feature;

use App\Jobs\TaskBecameOverdueJob;
use App\Models\Task;
use App\Models\User;
use App\Models\TaskOverdueLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_task_returns_task_and_uses_cache(): void
    {
        $task = Task::create([
            'title' => 'Тестовая задача',
            'description' => 'Проверка кэша',
            'status' => 'todo',
            'deadline' => now()->addDays(3),
            'deadline_status' => 'on_track',
        ]);

        $firstResponse = $this->getJson("/api/tasks/{$task->id}");

        $firstResponse
            ->assertStatus(200)
            ->assertJsonPath('cached', false)
            ->assertJsonPath('task.id', $task->id);

        $secondResponse = $this->getJson("/api/tasks/{$task->id}");

        $secondResponse
            ->assertStatus(200)
            ->assertJsonPath('cached', true)
            ->assertJsonPath('task.id', $task->id);
    }

    public function test_show_nonexistent_task_returns_404(): void
    {
        $response = $this->getJson('/api/tasks/99999');

        $response->assertStatus(404);
    }

    public function test_create_task_with_valid_data_returns_201(): void
    {
        $deadline = now()->addDays(7)->format('Y-m-d H:i:s');

        $response = $this->postJson('/api/tasks', [
            'title' => 'Новая задача',
            'description' => 'Описание задачи',
            'deadline' => $deadline,
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('message', 'Задача успешно создана')
            ->assertJsonPath('task.title', 'Новая задача')
            ->assertJsonPath('task.status', 'todo')
            ->assertJsonPath('task.deadline_status', 'on_track');

        $this->assertDatabaseHas('tasks', [
            'title' => 'Новая задача',
            'status' => 'todo',
        ]);
    }

    public function test_create_task_without_title_returns_422(): void
    {
        $response = $this->postJson('/api/tasks', [
            'description' => 'Без названия',
            'deadline' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_create_task_with_past_deadline_returns_422(): void
    {
        $response = $this->postJson('/api/tasks', [
            'title' => 'Просроченная задача',
            'deadline' => '2020-01-01 12:00:00',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['deadline']);
    }

    public function test_patch_status_without_token_returns_401(): void
    {
        $task = Task::create([
            'title' => 'Тестовая задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->addDays(3),
            'deadline_status' => 'on_track',
        ]);

        $response = $this->patchJson(
            "/api/tasks/{$task->id}/status",
            ['status' => 'done']
        );

        $response
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_patch_status_with_token_returns_200(): void
    {
        $user = User::factory()->create();

        $task = Task::create([
            'title' => 'Тестовая задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->addDays(3),
            'deadline_status' => 'on_track',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->patchJson(
                "/api/tasks/{$task->id}/status",
                ['status' => 'done']
            );

        $response
            ->assertStatus(200)
            ->assertJsonPath('task.status', 'done');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'done',
        ]);
    }

    public function test_done_to_todo_transition_returns_422(): void
    {
        $user = User::factory()->create();

        $task = Task::create([
            'title' => 'Завершённая задача',
            'description' => null,
            'status' => 'done',
            'deadline' => now()->addDays(3),
            'deadline_status' => 'on_track',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->patchJson(
                "/api/tasks/{$task->id}/status",
                ['status' => 'todo']
            );

        $response
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Недопустимый переход статуса: done → todo'
            );
    }

    public function test_patch_status_clears_task_cache(): void
    {
        $user = User::factory()->create();

        $task = Task::create([
            'title' => 'Задача с кэшем',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->addDays(3),
            'deadline_status' => 'on_track',
        ]);

        $firstResponse = $this->getJson("/api/tasks/{$task->id}");

        $firstResponse
            ->assertStatus(200)
            ->assertJsonPath('cached', false);

        $secondResponse = $this->getJson("/api/tasks/{$task->id}");

        $secondResponse
            ->assertStatus(200)
            ->assertJsonPath('cached', true);

        $token = $user->createToken('test-token')->plainTextToken;

        $patchResponse = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->patchJson(
                "/api/tasks/{$task->id}/status",
                ['status' => 'in_progress']
            );

        $patchResponse
            ->assertStatus(200)
            ->assertJsonPath('task.status', 'in_progress');

        $thirdResponse = $this->getJson("/api/tasks/{$task->id}");

        $thirdResponse
            ->assertStatus(200)
            ->assertJsonPath('cached', false)
            ->assertJsonPath('task.status', 'in_progress');
    }

    public function test_patch_nonexistent_task_returns_404(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->patchJson(
                '/api/tasks/99999/status',
                ['status' => 'done']
            );

        $response->assertStatus(404);
    }

    public function test_patch_with_invalid_status_returns_422(): void
    {
        $user = User::factory()->create();

        $task = Task::create([
            'title' => 'Тестовая задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->addDays(3),
            'deadline_status' => 'on_track',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->patchJson(
                "/api/tasks/{$task->id}/status",
                ['status' => 'invalid_status']
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_tasks_index_supports_status_filter_and_pagination(): void
    {
        Task::create([
            'title' => 'Todo задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->addDays(3),
            'deadline_status' => 'on_track',
        ]);

        Task::create([
            'title' => 'Done задача',
            'description' => null,
            'status' => 'done',
            'deadline' => now()->addDays(3),
            'deadline_status' => 'on_track',
        ]);

        $response = $this->getJson('/api/tasks?status=todo&per_page=1');

        $response
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'todo');
    }

    public function test_tasks_index_supports_deadline_status_filter(): void
    {
        Task::create([
            'title' => 'Просроченная задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->subHour(),
            'deadline_status' => 'on_track',
        ]);

        Task::create([
            'title' => 'Актуальная задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->addDays(3),
            'deadline_status' => 'on_track',
        ]);

        $response = $this->getJson('/api/tasks?deadline_status=overdue');

        $response
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Просроченная задача')
            ->assertJsonPath('data.0.deadline_status', 'overdue');
    }

    public function test_login_returns_sanctum_token(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'Успешная авторизация')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure([
                'message',
                'token',
                'user',
            ]);
    }

    public function test_login_rate_limit_returns_429_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'login@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $response = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_overdue_job_marks_task_overdue_and_creates_log(): void
    {
        $task = Task::create([
            'title' => 'Просроченная задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->subHour(),
            'deadline_status' => 'on_track',
        ]);

        (new TaskBecameOverdueJob($task->id))->handle();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'deadline_status' => 'overdue',
        ]);

        $this->assertDatabaseHas('task_overdue_logs', [
            'task_id' => $task->id,
        ]);
    }


    public function test_overdue_command_dispatches_job_for_task_with_past_deadline_regardless_of_deadline_status(): void
    {
        Queue::fake();

        $task = Task::create([
            'title' => 'Просроченная задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->subHour(),
            'deadline_status' => 'on_track',
        ]);

        $this->artisan('app:check-overdue-tasks')
            ->assertExitCode(0)
            ->expectsOutput("Задача #{$task->id}: Job отправлен в Redis.");

        Queue::assertPushed(TaskBecameOverdueJob::class, function (TaskBecameOverdueJob $job) use ($task): bool {
            return $job->taskId === $task->id;
        });

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'deadline_status' => 'on_track',
        ]);
    }

    public function test_overdue_command_does_not_dispatch_job_when_log_already_exists(): void
    {
        Queue::fake();

        $task = Task::create([
            'title' => 'Уже обработанная задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->subHour(),
            'deadline_status' => 'overdue',
        ]);

        TaskOverdueLog::create(['task_id' => $task->id]);

        $this->artisan('app:check-overdue-tasks')
            ->assertExitCode(0)
            ->expectsOutput('Новых просроченных задач не найдено.');

        Queue::assertNothingPushed();
    }

    public function test_overdue_command_dispatches_for_task_even_when_deadline_status_is_already_overdue_without_log(): void
    {
        Queue::fake();

        $task = Task::create([
            'title' => 'Просроченная без лога',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->subHour(),
            'deadline_status' => 'overdue',
        ]);

        $this->artisan('app:check-overdue-tasks')
            ->assertExitCode(0);

        Queue::assertPushed(TaskBecameOverdueJob::class, function (TaskBecameOverdueJob $job) use ($task): bool {
            return $job->taskId === $task->id;
        });
    }

    public function test_overdue_job_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        $task = Task::create([
            'title' => 'Просроченная задача',
            'description' => null,
            'status' => 'todo',
            'deadline' => now()->subHour(),
            'deadline_status' => 'on_track',
        ]);

        TaskBecameOverdueJob::dispatch($task->id);

        Queue::assertPushed(
            TaskBecameOverdueJob::class,
            function (TaskBecameOverdueJob $job) use ($task): bool {
                return $job->taskId === $task->id;
            }
        );
    }
}
