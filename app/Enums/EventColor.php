<?php

namespace App\Enums;

/**
 * Badge colors a calendar event can be shown with. The frontend maps each
 * value to its palette in resources/js/lib/calendar.ts.
 */
enum EventColor: string
{
    case Slate = 'slate';
    case Red = 'red';
    case Orange = 'orange';
    case Amber = 'amber';
    case Green = 'green';
    case Teal = 'teal';
    case Blue = 'blue';
    case Violet = 'violet';
    case Pink = 'pink';
}
