<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method array getCodeList()
 * @method null|UserOption findOne(array $conditions)
 */
class UserOptionCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return UserOption::class;
	}
}