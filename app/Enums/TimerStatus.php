<?php

namespace App\Enums;

enum TimerStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';
}
