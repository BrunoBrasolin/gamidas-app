<?php

namespace App\Enums;

enum PurchaseMode: string
{
    /** A total price divided into installments. */
    case Installments = 'installments';

    /** A known fixed amount charged every month for a number of months. */
    case FixedMonthly = 'fixed_monthly';
}
