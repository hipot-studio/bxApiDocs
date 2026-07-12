<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Internals\Group\GroupEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\File;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Scrum\ScrumGridRow;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\UserCollection;

class ScrumMapper
{
	public function mapToGridRow(
		GroupEntity $group,
		?File $image = null,
		?User $owner = null,
		?UserCollection $members = null,
		?int $numberOfModerators = null,
		?array $tags = null,
		?string $activityDate = null,
		?string $dateRelation = null,
		?string $dateView = null,
	): ScrumGridRow
	{
		$dateCreate = $group->getDateCreate();
		$dateActivity = $group->getDateActivity();

		$privacyType = $this->mapPrivacyType(
			$group->getVisible(),
			$group->getOpened(),
		);

		return new ScrumGridRow(
			id: $group->getId(),
			name: $group->getName(),
			image: $image,
			owner: $owner,
			dateCreate: $dateCreate instanceof DateTime ? $dateCreate->toString() : null,
			dateActivity: $dateActivity instanceof DateTime ? $dateActivity->toString() : null,
			activityDate: $activityDate,
			privacyType: $privacyType,
			members: $members,
			numberOfMembers: $group->getNumberOfMembers(),
			numberOfModerators: $numberOfModerators,
			closed: $group->getClosed(),
			scrumMasterId: $group->getScrumMasterId() > 0 ? $group->getScrumMasterId() : null,
			tags: $tags,
			dateRelation: $dateRelation,
			dateView: $dateView,
		);
	}

	private function mapPrivacyType(?bool $visible, ?bool $opened): string
	{
		if ($visible === false)
		{
			return PrivacyType::LEGACY_SCRUM_SECRET;
		}

		return PrivacyType::fromLegacyFlags($visible, $opened)->value;
	}
}
