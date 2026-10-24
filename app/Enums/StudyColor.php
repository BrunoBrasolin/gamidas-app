<?php

namespace App\Enums;

/**
 * Accent colors a study subject (its book cover) can use. The frontend maps
 * each value to its palette in resources/js/lib/study.ts.
 */
enum StudyColor: string
{
    case Terracotta = 'terracotta';
    case Ochre = 'ochre';
    case Olive = 'olive';
    case Sage = 'sage';
    case Teal = 'teal';
    case Navy = 'navy';
    case Plum = 'plum';
    case Rose = 'rose';
    case Graphite = 'graphite';
}
