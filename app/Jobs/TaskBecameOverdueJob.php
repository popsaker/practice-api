<?php

namespace App\Jobs;

use App\Models\TaskOverdueLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TaskBecameOverdueJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $taskId
    ) {
    }

    public function handle(): void
    {
        TaskOverdueLog::updateOrCreate(
            ['task_id' => $this->taskId],
            []
        );
    }
}
