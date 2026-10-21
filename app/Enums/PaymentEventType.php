<?php

namespace App\Enums;

enum PaymentEventType: string
{
    case Paid = 'paid';
    case Reopened = 'reopened';
}
