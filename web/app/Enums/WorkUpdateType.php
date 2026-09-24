<?php

namespace App\Enums;

enum WorkUpdateType: string
{
    case Start = 'START';
    case Before = 'BEFORE';
    case Progress = 'PROGRESS';
    case Issue = 'ISSUE';
    case Continuation = 'CONTINUATION';
    case Pause = 'PAUSE';
    case WaitingForMaterials = 'WAITING_FOR_MATERIALS';
    case Investigation = 'INVESTIGATION';
    case Completion = 'COMPLETION';
    case Other = 'OTHER';
}
