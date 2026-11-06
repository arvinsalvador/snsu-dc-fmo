<?php

namespace App\Enums;

enum TransportApprovalAuthority: string
{
    case Own = 'OWN_AUTHORITY';
    case CampusDirector = 'CAMPUS_DIRECTOR_INSTRUCTION';
    case FmoHead = 'FMO_HEAD_INSTRUCTION';

    public function isInstruction(): bool
    {
        return $this !== self::Own;
    }

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Under own authority',
            self::CampusDirector => 'As instructed by Campus Director',
            self::FmoHead => 'As instructed by FMO Head',
        };
    }
}
