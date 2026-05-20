<?php

namespace App\Services;

/**
 * Replica el hashing del backend PDNMX (src/library/BCrypt.ts).
 *
 * Pese al nombre "BCrypt", el backend usa cadenas de md5 con salt
 * derivado de Date.now() y formato "salt$rounds$hash":
 *
 *   salt    = Date.now()             (ms desde epoch — int)
 *   rounds  = 10 por default
 *   hashed  = md5(raw + salt)
 *   for (i = 0; i <= rounds; i++) hashed = md5(hashed)   // 11 iteraciones extra
 *
 * Cualquier intento de "modernizar" esto a bcrypt real rompe el login
 * en el sistema oficial. Mantener byte-exacto.
 */
class PndUserHash
{
    public const DEFAULT_ROUNDS = 10;

    public static function hash(string $raw, ?int $salt = null, int $rounds = self::DEFAULT_ROUNDS): string
    {
        $salt   = $salt ?? (int) round(microtime(true) * 1000);
        $hashed = md5($raw . $salt);
        for ($i = 0; $i <= $rounds; $i++) {
            $hashed = md5($hashed);
        }
        return $salt.'$'.$rounds.'$'.$hashed;
    }
}
