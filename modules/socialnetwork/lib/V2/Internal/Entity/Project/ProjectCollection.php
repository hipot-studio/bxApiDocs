<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method null|Project findOne(array $conditions)
 * @method null|Project findOneById(int $id, string $idKey = 'id')
 * @method ProjectCollection findAll(array $conditions)
 * @method ProjectCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method Project[] getIterator()
 * @method static ProjectCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method ProjectCollection filter(callable $callback)
 */
class ProjectCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Project::class;
	}
}
