<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Pdam\Http\Api\PdamCheckController;
use Nawasara\Pdam\Http\Api\PdamConnectionController;

/*
|--------------------------------------------------------------------------
| Endpoint WARGA, di belakang JWT Keycloak realm warga
|--------------------------------------------------------------------------
| Kontrak ini dipatok untuk tim Flutter: mengubah nama kunci atau bentuk
| jawaban setelah aplikasi dirilis berarti aplikasi lama rusak.
|
| POST, bukan GET, meski hanya membaca. Nomor sambungan menunjuk ke rumah
| seseorang, dan menaruhnya di URL membuatnya tercatat di log akses, riwayat
| proxy, dan riwayat peramban. Badan POST tidak.
*/

Route::post('/check', PdamCheckController::class)->name('check');

/*
| Nomor yang disimpan warga, supaya tidak perlu diketik ulang tiap bulan.
|
| Identitas warga selalu dari token. Tidak ada rute yang menerima citizen_sub
| dari pemanggil.
*/

Route::get('/connections', [PdamConnectionController::class, 'index'])->name('connections.index');
Route::post('/connections', [PdamConnectionController::class, 'store'])->name('connections.store');
Route::delete('/connections/{id}', [PdamConnectionController::class, 'destroy'])->name('connections.destroy');
