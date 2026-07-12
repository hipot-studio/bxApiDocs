<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method null|Absence findOne(array $conditions)
 * @method null|Absence findOneById(int $id, string $idKey = 'id')
 * @method AbsenceCollection findAll(array $conditions)
 * @method AbsenceCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method Absence[] getIterator()
 * @method static AbsenceCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method AbsenceCollection filter(callable $callback)
 */
class AbsenceCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Absence::class;
	}
}
