<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

/**
 * @method null|Flow findOne(array $conditions)
 * @method null|Flow findOneById(int $id, string $idKey = 'id')
 * @method FlowCollection findAll(array $conditions)
 * @method FlowCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method array getEmailList()
 * @method Flow[] getIterator()
 * @method static FlowCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method FlowCollection filter(callable $callback)
 */
class FlowCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Flow::class;
	}
}
