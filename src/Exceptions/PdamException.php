<?php

namespace Nawasara\Pdam\Exceptions;

use RuntimeException;

/**
 * PUDAM tidak dapat memberi jawaban yang bisa dipercaya.
 *
 * Mencakup tiga hal yang bagi warga sama saja, yaitu "coba lagi nanti":
 * PUDAM tidak terjangkau, kunci API kita ditolak, atau jawabannya berbentuk
 * aneh. Dipisah dari "nomor tidak ditemukan" yang BUKAN exception: nomor yang
 * salah adalah jawaban sah, bukan kegagalan.
 */
class PdamException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'pdam_error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function notConfigured(): static
    {
        return new static('Kredensial PDAM belum diatur di Vault (grup `pdam`).', 'pdam_not_configured');
    }

    public static function unreachable(?\Throwable $previous = null): static
    {
        return new static('PUDAM tidak dapat dihubungi.', 'pdam_unreachable', $previous);
    }

    /**
     * Kunci API ditolak. Bukan kesalahan warga, jadi tidak pernah dijawab
     * sebagai "nomor tidak ditemukan".
     */
    public static function authFailed(string $message): static
    {
        return new static("Kunci API PDAM ditolak: {$message}", 'pdam_auth_failed');
    }

    public static function unexpected(string $detail): static
    {
        return new static("Jawaban PDAM tidak dikenali: {$detail}", 'pdam_unexpected');
    }
}
