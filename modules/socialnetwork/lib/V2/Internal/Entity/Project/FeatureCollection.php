<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method null|Feature findOne(array $conditions)
 * @method null|Feature findOneById(string $id, string $idKey = 'id')
 * @method FeatureCollection findAll(array $conditions)
 * @method FeatureCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method Feature[] getIterator()
 * @method static FeatureCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method FeatureCollection filter(callable $callback)
 */
class FeatureCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Feature::class;
	}
}
