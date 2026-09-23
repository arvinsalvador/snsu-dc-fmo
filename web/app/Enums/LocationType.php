<?php

namespace App\Enums;

enum LocationType: string
{
    case Office = 'OFFICE';
    case Classroom = 'CLASSROOM';
    case Laboratory = 'LABORATORY';
    case Room = 'ROOM';
    case Lobby = 'LOBBY';
    case Hallway = 'HALLWAY';
    case ComfortRoom = 'COMFORT_ROOM';
    case Storage = 'STORAGE';
    case OutdoorArea = 'OUTDOOR_AREA';
    case CommonArea = 'COMMON_AREA';
    case Other = 'OTHER';
}
