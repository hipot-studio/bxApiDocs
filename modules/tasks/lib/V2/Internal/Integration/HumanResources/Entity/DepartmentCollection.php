<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\HumanResources\Entity;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method Department[] getIterator()
 */
class DepartmentCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Department::class;
	}
}
