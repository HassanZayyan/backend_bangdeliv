<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel penyimpan kode OTP verifikasi nomor WhatsApp.
     *
     * Backfill: seluruh user lama yang sudah punya nomor dianggap terverifikasi
     * agar rilis fitur ini tidak memaksa user existing melakukan OTP ulang.
     */
    public function up(): void
    {
        if (! Schema::hasTable('phone_verification_codes')) {
            Schema::create('phone_verification_codes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('phone', 20);
                $table->string('code_hash');
                $table->timestamp('expires_at');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamps();
            });
        }

        DB::table('users')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->whereNull('phone_verified_at')
            ->update(['phone_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verification_codes');
    }
};
