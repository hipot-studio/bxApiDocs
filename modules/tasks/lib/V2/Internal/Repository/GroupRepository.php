<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Integration\SocialNetwork\GroupProvider;
use Bitrix\Tasks\Integration\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\GroupMapper;

class GroupRepository implements GroupRepositoryInterface
{
	public function __construct(
		private readonly FileRepositoryInterface $fileRepository,
		private readonly GroupMapper $groupMapper,
	)
	{

	}

	public function getById(int $id): ?Entity\Group
	{
		$workgroup = GroupRegistry::getInstance()?->get($id);
		if ($workgroup === null)
		{
			return null;
		}

		if ($workgroup->getImageId() > 0)
		{
			$image = $this->fileRepository->getById($workgroup->getImageId());
		}
		else
		{
			$image = new Entity\File(
				src: $workgroup->getAvatarUrl(),
			);
		}

		return $this->groupMapper->mapToEntity($workgroup, $image);
	}

	public function getMembers(int $id): Entity\UserCollection
	{
		$workgroup = GroupRegistry::getInstance()?->get($id);
		if ($workgroup === null)
		{
			return new Entity\UserCollection();
		}

		$members = $workgroup->getMemberIdsWithRole();

		return $this->groupMapper->mapToUserCollection($members);
	}

	public function getType(int $id): ?string
	{
		return GroupProvider::getInstance()?->getGroupType($id)?->value;
	}
}
