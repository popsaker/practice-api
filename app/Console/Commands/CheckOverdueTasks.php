<?php

namespace App\Console\Commands;

use App\Jobs\TaskBecameOverdueJob;
use App\Models\Task;
use App\Models\TaskOverdueLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:check-overdue-tasks')]
#[Description('Checks overdue tasks and dispatches jobs to Redis queue')]
class CheckOverdueTasks extends Command
{
    public function handle(): int
    {
        $tasks = Task::query()
            ->where('deadline', '<', now())
            ->where('deadline_status', '!=', 'overdue')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('Просроченных новых задач не найдено.');

            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            $task->deadline_status = 'overdue';
            $task->save();

            if (!TaskOverdueLog::where('task_id', $task->id)->exists()) {
                TaskBecameOverdueJob::dispatch($task->id);

                $this->info(
                    "Задача #{$task->id} стала просроченной. Job отправлен в Redis."
                );
            }
        }

        return self::SUCCESS;
    }
}
