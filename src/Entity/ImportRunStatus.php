<?php

namespace App\Entity;

enum ImportRunStatus: string
{
    case Running = 'running';
    /** Every registration of the source was seen: removals were applied. */
    case Completed = 'completed';
    /** Every page was read, but not every registration was seen: updates applied, nothing removed. */
    case Incomplete = 'incomplete';
    /** The source failed: pages read before the failure are applied, nothing removed. */
    case Failed = 'failed';
}
