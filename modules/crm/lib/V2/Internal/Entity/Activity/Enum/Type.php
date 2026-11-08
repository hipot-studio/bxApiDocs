<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity\Enum;

/**
 * Kind of an activity, independent of the pair of the provider and its type: a meeting, a call, a task
 * and an email stay kinds of their own even where a provider handles them.
 *
 * The zero of the column names no kind and has no member here.
 */
enum Type: string
{
	case Meeting = 'meeting';
	case Call = 'call';
	case Task = 'task';
	case Email = 'email';
	case Activity = 'activity';
	case Provider = 'provider';
}
