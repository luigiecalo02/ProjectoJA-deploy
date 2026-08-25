<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 160);
            $table->string('banco', 160)->nullable();
            $table->string('tipo_cuenta', 32);
            $table->string('numero_cuenta', 64);
            $table->string('titular', 160)->nullable();
            $table->string('identificacion_titular', 32)->nullable();
            $table->unsignedBigInteger('qr_file_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->foreign('qr_file_id')->references('id')->on('files')->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('cuenta_bancaria_id')->nullable()->after('metodo_pago');
            $table->foreign('cuenta_bancaria_id')->references('id')->on('cuentas_bancarias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['cuenta_bancaria_id']);
            $table->dropColumn('cuenta_bancaria_id');
        });
        Schema::dropIfExists('cuentas_bancarias');
    }
};
