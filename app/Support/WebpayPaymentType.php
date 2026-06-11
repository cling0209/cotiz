<?php

namespace App\Support;

final class WebpayPaymentType
{
    public const CARD_DEBIT = 'debit';

    public const CARD_CREDIT = 'credit';

    public const CARD_PREPAID = 'prepaid';

    public const CARD_UNKNOWN = 'unknown';

    /**
     * Map Transbank payment_type_code to card category.
     *
     * @see https://www.transbankdevelopers.cl/referencia/webpay
     */
    public static function resolveCardType(?string $paymentTypeCode): string
    {
        return match ($paymentTypeCode) {
            'VD' => self::CARD_DEBIT,
            'VP' => self::CARD_PREPAID,
            'VN', 'VC', 'SI', 'S2', 'NC' => self::CARD_CREDIT,
            default => self::CARD_UNKNOWN,
        };
    }

    public static function label(?string $paymentTypeCode): string
    {
        return match ($paymentTypeCode) {
            'VD' => 'Débito Redcompra',
            'VP' => 'Prepago',
            'VN' => 'Crédito (1 cuota)',
            'VC' => 'Crédito (cuotas)',
            'SI' => 'Crédito (3 cuotas sin interés)',
            'S2' => 'Crédito (2 cuotas sin interés)',
            'NC' => 'Crédito (N cuotas sin interés)',
            default => 'Desconocido',
        };
    }
}
