<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

/**
 * @method null|TaskMember findOne(array $conditions)
 * @method null|TaskMember findOneById(int $id, string $idKey = 'id')
 * @method TaskMemberCollection findAll(array $conditions)
 * @method TaskMemberCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method TaskMember[] getIterator()
 * @method static TaskMemberCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method TaskMemberCollection filter(callable $callback)
 */
class TaskMemberCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return TaskMember::class;
	}
}
