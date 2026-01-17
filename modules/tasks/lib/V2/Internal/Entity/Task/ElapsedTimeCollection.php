<?php

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method null|ElapsedTime findOne(array $conditions)
 * @method null|ElapsedTime findOneById(int $id, string $idKey = 'id')
 * @method ElapsedTimeCollection findAll(array $conditions)
 * @method ElapsedTimeCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method ElapsedTime[] getIterator()
 * @method static ElapsedTimeCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method ElapsedTimeCollection filter(callable $callback)
 */
class ElapsedTimeCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return ElapsedTime::class;
	}
}
