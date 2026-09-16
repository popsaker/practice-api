<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('status', [
                'todo',
                'in_progress',
                'done',
            ])->default('todo');

            $table->dateTime('deadline');

            $table->enum('deadline_status', [
                'overdue',
                'due_soon',
                'on_track',
            ])->default('on_track');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
