<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:todo,in_progress,done'],
            'deadline_status' => ['nullable', 'in:overdue,due_soon,on_track'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Task::query();

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['deadline_status'])) {
            $deadlineStatus = $validated['deadline_status'];

            if ($deadlineStatus === 'overdue') {
                $query->where('deadline', '<', now());
            } elseif ($deadlineStatus === 'due_soon') {
                $query->whereBetween('deadline', [
                    now(),
                    now()->addHours(24),
                ]);
            } elseif ($deadlineStatus === 'on_track') {
                $query->where('deadline', '>', now()->addHours(24));
            }
        }

        $perPage = $validated['per_page'] ?? 10;

        $tasks = $query->paginate($perPage);

        $tasks->getCollection()->transform(function ($task) {
            $task->deadline_status = $this->calculateDeadlineStatus($task);

            return $task;
        });

        return TaskResource::collection($tasks);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:todo,in_progress,done'],
            'deadline' => ['required', 'date', 'after_or_equal:now'],
        ]);

        $task = new Task();

        $task->title = $validated['title'];
        $task->description = $validated['description'] ?? null;
        $task->status = $validated['status'] ?? 'todo';
        $task->deadline = $validated['deadline'];
        $task->deadline_status = $this->calculateDeadlineStatus($task);

        $task->save();

        return response()->json([
            'message' => 'Задача успешно создана',
            'task' => new TaskResource($task),
        ], 201);
    }

    public function show(int $id)
    {
        $cacheKey = "task:{$id}";

        $cached = Cache::has($cacheKey);

        if ($cached) {
            $cachedTask = Cache::get($cacheKey);

            $task = new Task();

            $task->setRawAttributes($cachedTask['attributes']);
            $task->exists = true;

            $task->setRelations($cachedTask['relations'] ?? []);
        } else {
            $task = Task::findOrFail($id);

            $task->deadline_status = $this->calculateDeadlineStatus($task);

            Cache::put(
                $cacheKey,
                [
                    'attributes' => $task->getAttributes(),
                    'relations' => $task->getRelations(),
                ],
                now()->addSeconds(60)
            );
        }

        return response()->json([
            'task' => new TaskResource($task),
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
        $task->deadline_status = $this->calculateDeadlineStatus($task);
        $task->save();

        Cache::forget("task:{$id}");

        return response()->json([
            'message' => 'Статус задачи успешно изменён',
            'task' => new TaskResource($task),
            'cached' => false,
        ]);
    }

    private function calculateDeadlineStatus(Task $task): string
    {
        if ($task->deadline->isPast()) {
            return 'overdue';
        }

        if ($task->deadline->lessThanOrEqualTo(now()->addHours(24))) {
            return 'due_soon';
        }

        return 'on_track';
    }
}
