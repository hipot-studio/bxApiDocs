<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template\Enum;

/**
 * Explicit deadline type of a regular template (REPLICATE_PARAMS.DEADLINE_TYPE).
 *
 * CreationDay sets the deadline on the creation day of the task. Without it a zero or absent
 * DEADLINE_OFFSET means no deadline; a positive offset always wins over the type.
 */
enum DeadlineType: string
{
	case CreationDay = 'creation_day';
}
