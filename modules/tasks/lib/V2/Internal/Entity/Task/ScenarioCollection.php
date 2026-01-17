<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\AbstractBackedEnumCollection;

/**
 * @method Scenario[] getIterator()
 */
class ScenarioCollection extends AbstractBackedEnumCollection
{
	protected static function getEnumClass(): string
	{
		return Scenario::class;
	}
}
