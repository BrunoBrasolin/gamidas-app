<?php

namespace App\Enums;

/**
 * Predefined icons for calendar events. The frontend maps each value to a
 * Lucide icon (or emoji) in resources/js/lib/calendar.ts.
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
    case Beach = 'beach';
    case Surf = 'surf';
    case Medicine = 'medicine';
    case Swimming = 'swimming';
    case Gymnastics = 'gymnastics';
    case Nails = 'nails';
    case Coding = 'coding';
    case Trash = 'trash';
}
