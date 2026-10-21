<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | All monetary values are stored as integer cents of this currency.
    |
    */

    'currency' => 'BRL',

    /*
    |--------------------------------------------------------------------------
    | Recurring Expense Generation
    |--------------------------------------------------------------------------
    |
    | Open-ended recurring expenses are materialized into monthly occurrences
    | up to this many months after the current month. Viewing a later month
    | generates it on demand, up to "max_months_ahead".
    |
    */

    'generation_months_ahead' => (int) env('FINANCE_GENERATION_MONTHS_AHEAD', 2),

    'max_months_ahead' => 24,

    /*
    |--------------------------------------------------------------------------
    | Upcoming Due Dates
    |--------------------------------------------------------------------------
    |
    | Number of days ahead that the dashboard lists unpaid expenses as upcoming.
    |
    */

    'upcoming_days' => 15,

];
