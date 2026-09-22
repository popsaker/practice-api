<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
}
