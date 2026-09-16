<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TaskController extends Controller
{
    public function show(int $id)
    {
        $cacheKey = "task:{$id}";

        $cached = Cache::has($cacheKey);

        if ($cached) {
            $task = Cache::get($cacheKey);
        } else {
            $taskModel = Task::findOrFail($id);

            $deadline = $taskModel->deadline;

            if ($deadline->isPast()) {
                $deadlineStatus = 'overdue';
            } elseif ($deadline->lessThanOrEqualTo(now()->addHours(24))) {
                $deadlineStatus = 'due_soon';
            } else {
                $deadlineStatus = 'on_track';
            }

            $taskModel->deadline_status = $deadlineStatus;

            $task = $taskModel->toArray();

            Cache::put(
                $cacheKey,
                $task,
                now()->addSeconds(60)
            );
        }

        return response()->json([
            'task' => $task,
            'cached' => $cached,
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $task = Task::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'in:todo,in_progress,done'],
        ]);

        $allowedTransitions = [
            'todo' => ['todo', 'in_progress', 'done'],
            'in_progress' => ['in_progress', 'todo', 'done'],
            'done' => ['done', 'in_progress'],
        ];

        if (!in_array(
            $validated['status'],
            $allowedTransitions[$task->status],
            true
        )) {
            return response()->json([
                'message' => "Недопустимый переход статуса: {$task->status} → {$validated['status']}",
            ], 422);
        }

        $task->status = $validated['status'];
        $task->save();

        Cache::forget("task:{$id}");

        return response()->json([
            'message' => 'Статус задачи успешно изменён',
            'task' => $task,
            'cached' => false,
        ]);
    }
}
