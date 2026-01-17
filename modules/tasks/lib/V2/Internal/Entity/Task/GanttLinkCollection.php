<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method GanttLink[] getIterator()
 */
class GanttLinkCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return GanttLink::class;
	}
}
