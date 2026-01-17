<?php

namespace Bitrix\Tasks\V2\Internal\Entity;

/**
 * @method null|UserFieldScheme findOne(array $conditions)
 * @method null|UserFieldScheme findOneById(int $id, string $idKey = 'id')
 * @method UserFieldSchemeCollection findAll(array $conditions)
 * @method UserFieldSchemeCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method static UserFieldSchemeCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method UserFieldSchemeCollection filter(callable $callback)
 * @method UserFieldScheme[] getIterator()
 */
class UserFieldSchemeCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return UserFieldScheme::class;
	}
}
