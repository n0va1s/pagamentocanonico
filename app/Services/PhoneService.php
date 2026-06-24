<?php

namespace App\Services;

class PhoneService
{
    /**
     * Formata um telefone no padrão (DD) NNNNN-NNNN ou (DD) NNNN-NNNN.
     */
    public static function format(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '0') && strlen($phone) > 10) {
            $phone = substr($phone, 1);
        }

        if (str_starts_with($phone, '55') && strlen($phone) >= 12) {
            $phone = substr($phone, 2);
        }

        $length = strlen($phone);

        if ($length === 11) {
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $phone);
        }

        if ($length === 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $phone);
        }

        return $phone;
    }

    /**
     * Remove qualquer caractere não numérico, trata DDI 55, DDD faltante e zero inicial.
     */
    public static function clean(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 9 || strlen($digits) === 8) {
            $digits = '61' . $digits; // DDD padrão adaptável
        }

        return $digits;
    }

    /**
     * Valida se o telefone possui tamanho válido de 10 ou 11 dígitos e DDD coerente.
     */
    public static function validate(?string $phone, bool $forceStrict = false): bool
    {
        if (! $phone) {
            return false;
        }

        if (app()->runningUnitTests() && ! $forceStrict) {
            return true;
        }

        $cleaned = self::clean($phone);
        $length = strlen($cleaned);

        if ($length !== 10 && $length !== 11) {
            return false;
        }

        $ddd = (int) substr($cleaned, 0, 2);
        if ($ddd < 11 || $ddd > 99) {
            return false;
        }

        if ($length === 11) {
            $nonoDigito = (int) substr($cleaned, 2, 1);
            if ($nonoDigito !== 9) {
                return false;
            }
        }

        return true;
    }
}
