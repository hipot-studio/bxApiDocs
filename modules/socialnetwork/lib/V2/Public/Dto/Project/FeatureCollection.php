<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Socialnetwork\V2\Public\Dto\AbstractCollection;

/**
 * @method null|Feature findOne(array $conditions)
 * @method null|Feature findOneById(string $id, string $idKey = 'id')
 * @method FeatureCollection findAll(array $conditions)
 * @method FeatureCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method Feature[] getIterator()
 * @method static FeatureCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method FeatureCollection filter(callable $callback)
 */
final class FeatureCollection extends AbstractCollection
{
	protected static function getItemClass(): string
	{
		return Feature::class;
	}
}
