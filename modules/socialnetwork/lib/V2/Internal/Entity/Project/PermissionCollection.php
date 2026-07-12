<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method null|Permission findOne(array $conditions)
 * @method null|Permission findOneById(string $id, string $idKey = 'feature')
 * @method PermissionCollection findAll(array $conditions)
 * @method PermissionCollection findAllByIds(array $ids, string $idKey = 'feature')
 * @method Permission[] getIterator()
 * @method static PermissionCollection mapFromIds(array $ids, string $idKey = 'feature')
 * @method PermissionCollection filter(callable $callback)
 */
class PermissionCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Permission::class;
	}
}
