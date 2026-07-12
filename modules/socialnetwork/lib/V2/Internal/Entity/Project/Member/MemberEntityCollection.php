<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method MemberEntity|null findOneById(int $id)
 * @method MemberEntity|null getFirstEntity()
 * @method MemberEntity[] getIterator()
 */
class MemberEntityCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return MemberEntity::class;
	}

	public function filterByType(MemberEntityType $type): static
	{
		return $this->filter(static fn(MemberEntity $entity): bool => $entity->type === $type);
	}
}
