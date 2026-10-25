<?php

namespace App\Enums;

/**
 * Predefined icons for calendar events. The frontend maps each value to a
 * Lucide icon in resources/js/lib/calendar.ts.
 */
enum EventIcon: string
{
    case Calendar = 'calendar';
    case Cake = 'cake';
    case Party = 'party';
    case Heart = 'heart';
    case Health = 'health';
    case Work = 'work';
    case Study = 'study';
    case Travel = 'travel';
    case Home = 'home';
    case Car = 'car';
    case Shopping = 'shopping';
    case Money = 'money';
    case Family = 'family';
    case Reminder = 'reminder';
    case Pet = 'pet';
    case Sport = 'sport';
    case Music = 'music';
}
