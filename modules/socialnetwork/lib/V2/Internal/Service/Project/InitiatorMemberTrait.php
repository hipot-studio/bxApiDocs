<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;

trait InitiatorMemberTrait
{
	private function ensureInitiatorIsMember(
		?int $ownerId,
		int $userId,
		bool $isCurrentUserModuleAdmin,
		?MemberEntityCollection $members,
		?MemberEntityCollection $moderatorMembers,
	): ?MemberEntityCollection
	{
		if (
			$ownerId === null
			|| $ownerId === $userId
			|| $isCurrentUserModuleAdmin
			|| $this->containsUser($members, $userId)
			|| $this->containsUser($moderatorMembers, $userId)
		)
		{
			return $members;
		}

		return new MemberEntityCollection(
			...array_merge(
				$members?->getEntities() ?? [],
				[new MemberEntity(id: $userId, type: MemberEntityType::User)],
			),
		);
	}

	private function containsUser(?MemberEntityCollection $members, int $userId): bool
	{
		return $members?->findOne([
			'id' => $userId,
			'type' => MemberEntityType::User,
		]) !== null;
	}
}
