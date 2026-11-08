<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity\Enum;

/**
 * Completion state of an activity.
 *
 * Carries what the boolean "completed" cannot: an activity completed by automation stays
 * {@see self::AutoCompleted} through further updates and is deliberately excluded from the
 * deal/lead statistics, where "completed" means {@see self::Completed} only.
 *
 * {@see self::Undefined} is not a state anything writes - it is what legacy rows that were
 * inserted bypassing the write pipeline carry.
 *
 * The correspondence with the stored column is owned by the field map, not by this enum.
 */
enum CompletionStatus: string
{
	case Undefined = 'undefined';
	case Waiting = 'waiting';
	case Completed = 'completed';
	case AutoCompleted = 'autoCompleted';
}
