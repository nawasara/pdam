<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor sambungan yang disimpan warga, supaya tidak perlu diketik ulang tiap
 * bulan.
 *
 * Berbeda dari PBB: di sana pendaftaran adalah pernyataan kepemilikan yang
 * menjadi dasar Bagian Keuangan menghubungi warga. Di sini murni kenyamanan,
 * jadi tidak ada kolom hubungan (pemilik, ahli waris) dan tidak ada yang
 * menghubungi siapa pun dari tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_pdam_connections', function (Blueprint $table) {
            // UUID: id-nya keluar ke aplikasi warga lewat
            // `DELETE /pdam/connections/{id}` (AGENTS.md §10a).
            $table->uuid('id')->primary();

            $table->string('citizen_sub', 64)->index();
            $table->string('connection_number', 20)->index();

            // Nama yang diberikan warga, mis. "Rumah Ibu". Opsional.
            $table->string('label', 40)->nullable();

            // Disimpan SUDAH tersamar. Daftar ini tidak perlu nama pelanggan
            // utuh, dan yang tidak disimpan tidak dapat bocor. Alamat sama
            // sekali tidak disimpan.
            $table->string('name_masked', 64)->nullable();

            // Cuplikan saat terakhir dicek.
            $table->unsignedBigInteger('total_due')->nullable();
            $table->boolean('has_arrears')->nullable();
            $table->unsignedSmallInteger('unpaid_count')->nullable();
            $table->timestamp('refreshed_at')->nullable();

            $table->timestamp('saved_at');
            $table->timestamps();
            $table->softDeletes();

            // Unik pada PASANGAN. Satu rumah lazim diurus beberapa anggota
            // keluarga, masing-masing dari aplikasinya sendiri.
            $table->unique(['citizen_sub', 'connection_number'], 'nawasara_pdam_connections_sub_no_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_pdam_connections');
    }
};
