<?php

namespace App\Enum;

enum StructureType: string
{
    case Lycee = 'lycee';
    case MissionLocale = 'mission_locale';
    case FranceTravail = 'france_travail';
    case Other = 'other';
}
