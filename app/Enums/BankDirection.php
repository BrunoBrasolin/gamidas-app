<?php

namespace App\Enums;

/**
 * Whether money left the member (a purchase, a PIX sent) or came in (a PIX
 * received, a refund).
 */
enum BankDirection: string
{
    case Out = 'out';
    case In = 'in';
}
