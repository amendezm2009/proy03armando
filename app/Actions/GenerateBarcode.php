<?php

namespace App\Actions;

/**
 * Códigos de barras EAN-13.
 *
 * Los productos sin código del fabricante reciben uno interno: el prefijo "2" (el rango 200-299 de
 * EAN-13 está reservado a uso interno de cada negocio, no choca con códigos comerciales) más su id
 * con ceros a la izquierda y el dígito de control. Como el id es único, el código también lo es.
 *
 * USO: GenerateBarcode::forId($product->id);   GenerateBarcode::isValid('4006381333931');
 */
class GenerateBarcode
{
    public const string INTERNAL_PREFIX = '2';

    /**
     * EAN-13 interno de un producto: "2" + id de 11 dígitos + dígito de control.
     */
    public static function forId(int $id): string
    {
        $body = self::INTERNAL_PREFIX.str_pad((string) $id, 11, '0', STR_PAD_LEFT);

        return $body.self::checkDigit($body);
    }

    /**
     * Dígito de control de los 12 primeros dígitos: los de posición impar pesan 1 y los de posición par, 3.
     */
    public static function checkDigit(string $twelveDigits): int
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * ¿Son 13 dígitos con un dígito de control correcto?
     */
    public static function isValid(string $code): bool
    {
        return preg_match('/^\d{13}$/', $code) === 1
            && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }
}
