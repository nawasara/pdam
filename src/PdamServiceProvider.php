<?php

namespace Nawasara\Pdam;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nawasara\Pdam\Support\LookupLimits;
use Nawasara\Pdam\Exceptions\PdamException;
use Nawasara\Pdam\Services\PdamClient;

/**
 * Paket PDAM: perantara tagihan air PUDAM Tirta Katong untuk aplikasi warga.
 *
 * Sengaja tipis, mengikuti nawasara/pbb: tidak ada halaman admin, menu, atau
 * sync job. Tagihan tidak pernah disalin; setiap pengecekan menembus langsung
 * ke PUDAM, karena tagihan yang disalin akan basi tanpa memberi tanda.
 *
 * Yang disimpan hanya dua, dan keduanya terpisah dengan sengaja:
 *
 *   nawasara_pdam_checks       jejak pengecekan, untuk statistik dan untuk
 *                              mengenali percobaan menebak nomor
 *   nawasara_pdam_connections  nomor yang disimpan warga supaya tidak perlu
 *                              diketik ulang tiap bulan
 */
class PdamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-pdam.php', 'nawasara-pdam');

        // Kredensial dari Vault, dibaca saat dibutuhkan, bukan saat boot:
        // aplikasi tetap menyala walau grup `pdam` belum diisi.
        //
        // Pengikatan ini juga yang membuat tombol "Uji" di panel Vault bekerja.
        // Vault memanggil `PdamClient@testConnection` lewat container, dan
        // tanpa ini container tidak tahu dari mana alamat dan kuncinya.
        $this->app->bind(PdamClient::class, function () {
            return PdamClient::fromVault() ?? throw PdamException::notConfigured();
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // Limiter didaftarkan SEBELUM rute yang memakainya.
        $this->registerLookupLimiter();
        $this->registerCitizenRoutes();

        $this->publishes([
            __DIR__.'/../config/nawasara-pdam.php' => config_path('nawasara-pdam.php'),
        ], 'nawasara-pdam-config');
    }

    /**
     * Limiter khusus pengecekan nomor sambungan (audit keamanan 15 Oktober 2026,
     * temuan S13). Batas umum 60/menit per akun terlalu longgar untuk data
     * yang dapat dipanen dengan menebak nomor berurutan; uraiannya di
     * LookupLimits.
     */
    protected function registerLookupLimiter(): void
    {
        RateLimiter::for('nawasara-pdam-lookup', fn (Request $request) => LookupLimits::build(
            $request->attributes->get('citizen_sub'),
            (string) $request->ip(),
            (array) config('nawasara-pdam.lookup_limits', []),
        ));
    }

    /**
     * Rute aplikasi warga, di belakang `api.citizen` (JWT realm warga).
     *
     * `throttle:nawasara-citizen` memakai limiter nawasara/api yang dikunci
     * pada `sub`, bukan IP: ribuan ponsel warga berbagi IP operator. Remnya
     * tetap penting, karena tiap panggilan menembus ke PUDAM, dan karena
     * nomor sambungan yang pendek dan berurutan mengundang orang menebak.
     */
    protected function registerCitizenRoutes(): void
    {
        $prefix = (string) config('nawasara-api.route.prefix', 'api/v1').'/pdam';

        Route::prefix($prefix)
            ->middleware(['api', 'api.citizen', 'throttle:nawasara-citizen'])
            ->name('nawasara-pdam.citizen.')
            ->group(__DIR__.'/../routes/citizen.php');
    }
}
