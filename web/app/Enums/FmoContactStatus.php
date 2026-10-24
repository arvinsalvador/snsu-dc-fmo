<?php

namespace App\Enums;

enum FmoContactStatus: string
{
    case Open = 'OPEN';
    case Responded = 'RESPONDED';
    case Closed = 'CLOSED';
}
