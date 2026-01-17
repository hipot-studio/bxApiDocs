<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method Permission[] getIterator()
 */
class PermissionCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Permission::class;
	}
}
