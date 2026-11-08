<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity\Enum;

/**
 * Direction of the communication an activity represents.
 *
 * {@see self::Undefined} is the default of an activity that is not a communication at all
 * (a task, a to-do), not a missing value.
 *
 * The correspondence with the stored column is owned by the field map, not by this enum.
 */
enum Direction: string
{
	case Undefined = 'undefined';
	case Incoming = 'incoming';
	case Outgoing = 'outgoing';
}
