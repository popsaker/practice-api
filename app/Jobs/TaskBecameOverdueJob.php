<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskOverdueLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TaskBecameOverdueJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $taskId
    ) {
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $task = Task::query()->lockForUpdate()->find($this->taskId);

            if (!$task || !$task->deadline || $task->deadline->isFuture()) {
                return;
            }

            $task->deadline_status = 'overdue';
            $task->save();

            TaskOverdueLog::firstOrCreate([
                'task_id' => $task->id,
            ]);

            Cache::forget("task:{$task->id}");
        });
    }
}
