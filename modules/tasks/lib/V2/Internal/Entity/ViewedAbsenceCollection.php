<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

/**
 * @method null|ViewedAbsence findOne(array $conditions)
 * @method null|ViewedAbsence findOneById(int $id, string $idKey = 'id')
 * @method ViewedAbsenceCollection findAll(array $conditions)
 * @method ViewedAbsenceCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method ViewedAbsence[] getIterator()
 * @method static ViewedAbsenceCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method ViewedAbsenceCollection filter(callable $callback)
 */
class ViewedAbsenceCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return ViewedAbsence::class;
	}
}
