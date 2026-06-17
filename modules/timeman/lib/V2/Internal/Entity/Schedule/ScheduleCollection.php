<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Schedule;

use Bitrix\Timeman\V2\Internal\Entity\AbstractEntityCollection;

final class ScheduleCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Schedule::class;
	}
}
