<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak pengecekan tagihan air.
 *
 * Dua kegunaan: berapa warga yang memakai fitur ini, dan mengenali percobaan
 * menebak nomor. Nomor sambungan hanya 10 digit dan berurutan, jadi satu akun
 * yang mengecek ratusan nomor yang hampir semuanya `found=false` adalah tanda
 * yang jelas, dan tanpa tabel ini tidak ada yang bisa melihatnya.
 *
 * ⚠️ BUKAN daftar pelanggan milik warga. Warga lazim mengecekkan tagihan rumah
 * orang tuanya atau tempat kosnya. Yang menyatakan "ini sambungan saya" adalah
 * `nawasara_pdam_connections`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_pdam_checks', function (Blueprint $table) {
            // bigint, bukan UUID: id baris ini tidak pernah keluar dari sistem
            // (AGENTS.md §10a). Tabel ini tumbuh seiring pemakaian, jadi indeks
            // yang ramping yang penting.
            $table->id();

            // `sub` dari JWT warga. Tanpa foreign key: warga bukan baris `users`.
            $table->string('citizen_sub', 64)->index();

            // Hanya angka, sama seperti yang dikirim ke PUDAM.
            $table->string('connection_number', 20)->index();

            // Membedakan pemeriksaan sah dari percobaan menebak.
            $table->boolean('found')->default(false);

            $table->unsignedBigInteger('total_due')->nullable();

            $table->timestamp('checked_at')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_pdam_checks');
    }
};
