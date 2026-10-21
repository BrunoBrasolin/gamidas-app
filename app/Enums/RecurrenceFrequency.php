<?php

namespace App\Enums;

/**
 * How often a recurring calendar event repeats; the rule's interval
 * multiplies it ("every 2 weeks").
 */
enum RecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
