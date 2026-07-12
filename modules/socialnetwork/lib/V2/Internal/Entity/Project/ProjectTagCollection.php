<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method null|ProjectTag findOne(array $conditions)
 * @method null|ProjectTag findOneById(int $id, string $idKey = 'id')
 * @method ProjectTagCollection findAll(array $conditions)
 * @method ProjectTagCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method ProjectTag[] getIterator()
 * @method static ProjectTagCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method ProjectTagCollection filter(callable $callback)
 */
class ProjectTagCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return ProjectTag::class;
	}

	public function getNameList(): array
	{
		return array_values(
			array_filter(
				array_map(static fn(ProjectTag $tag): ?string => $tag->name, $this->getEntities()),
				static fn(?string $name): bool => $name !== null && $name !== '',
			),
		);
	}
}
