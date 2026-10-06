<?php

namespace App\Console\Commands;

use App\Jobs\TaskBecameOverdueJob;
use App\Models\Task;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:check-overdue-tasks')]
#[Description('Checks overdue tasks and dispatches jobs to Redis queue')]
class CheckOverdueTasks extends Command
{
    public function handle(): int
    {
        $tasks = Task::query()
            ->where('deadline', '<', now())
            ->whereDoesntHave('overdueLog')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('Новых просроченных задач не найдено.');

            return self::SUCCESS;
        }

        $hasFailures = false;

        foreach ($tasks as $task) {
            try {
                TaskBecameOverdueJob::dispatch($task->id);

                $this->info(
                    "Задача #{$task->id}: Job отправлен в Redis."
                );
            } catch (Throwable $e) {
                $hasFailures = true;

                $this->error(
                    "Задача #{$task->id}: не удалось отправить Job в очередь: {$e->getMessage()}"
                );
            }
        }

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }
}
