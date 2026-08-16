<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_super')) {
                $table->boolean('is_super')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('users', 'is_admin')) {
                $table->boolean('is_admin')->default(false)->after('is_super');
            }
        });

        if (Schema::hasTable('role_user')) {
            $superIds = DB::table('roles')->where('is_super', true)->pluck('id')->all();
            $adminId = DB::table('roles')->where('name', 'admin')->value('id');

            if ($superIds !== []) {
                $userIds = DB::table('role_user')
                    ->whereIn('role_id', $superIds)
                    ->pluck('user_id')
                    ->unique()
                    ->all();

                if ($userIds !== []) {
                    DB::table('users')->whereIn('id', $userIds)->update(['is_super' => true]);
                }
            }

            if ($adminId) {
                $userIds = DB::table('role_user')
                    ->where('role_id', $adminId)
                    ->pluck('user_id')
                    ->unique()
                    ->all();

                if ($userIds !== []) {
                    DB::table('users')->whereIn('id', $userIds)->update(['is_admin' => true]);
                }
            }

            Schema::dropIfExists('role_user');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->primary(['user_id', 'role_id']);
            });
        }

        $superRoleId = DB::table('roles')->where('is_super', true)->value('id');
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($superRoleId) {
            $rows = DB::table('users')->where('is_super', true)->pluck('id')
                ->map(fn ($id) => ['user_id' => $id, 'role_id' => $superRoleId])
                ->all();
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('role_user')->insertOrIgnore($chunk);
            }
        }

        if ($adminRoleId) {
            $rows = DB::table('users')->where('is_admin', true)->where('is_super', false)->pluck('id')
                ->map(fn ($id) => ['user_id' => $id, 'role_id' => $adminRoleId])
                ->all();
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('role_user')->insertOrIgnore($chunk);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_admin')) {
                $table->dropColumn('is_admin');
            }
            if (Schema::hasColumn('users', 'is_super')) {
                $table->dropColumn('is_super');
            }
        });
    }
};
