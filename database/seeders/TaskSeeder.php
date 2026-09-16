<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        Task::updateOrCreate(
            ['id' => 1],
            [
                'title' => 'Тестовая задача',
                'description' => 'Проверка API',
                'status' => 'todo',
                'deadline' => now()->addDays(3),
                'deadline_status' => 'on_track',
            ]
        );

        Task::updateOrCreate(
            ['id' => 2],
            [
                'title' => 'Тест due_soon',
                'description' => 'Проверка дедлайна менее 24 часов',
                'status' => 'todo',
                'deadline' => now()->addHours(5),
                'deadline_status' => 'due_soon',
            ]
        );

        Task::updateOrCreate(
            ['id' => 3],
            [
                'title' => 'Тест overdue',
                'description' => 'Проверка просроченного дедлайна',
                'status' => 'todo',
                'deadline' => now()->subDay(),
                'deadline_status' => 'overdue',
            ]
        );
    }
}
