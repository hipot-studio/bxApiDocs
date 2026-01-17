<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\Collection;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Tasks\Integration\Extranet\User;
use Bitrix\Tasks\Integration\SocialNetwork\Collab\Provider\CollabDefaultProvider;
use Bitrix\Tasks\Integration\SocialNetwork\GroupProvider;
use Bitrix\Tasks\Integration\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\GroupMapper;
use CSocNetGroup;

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

		return $this->groupMapper->mapToEntity($workgroup, $this->getImage($workgroup));
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

	public function getByIds(array $ids): Entity\GroupCollection
	{
		if (empty($ids))
		{
			return new Entity\GroupCollection();
		}

		$result = CSocNetGroup::GetList(
			arFilter: ['@ID' => $ids],
			arSelectFields: ['ID', 'NAME', 'IMAGE_ID', 'AVATAR_TYPE', 'TYPE', 'VISIBLE', 'SITE_IDS'],
		);

		$groups = [];
		while ($group = $result->Fetch())
		{
			$groups[] = $group;
		}

		$imageIds = array_column($groups, 'IMAGE_ID');

		Collection::normalizeArrayValuesByInt($imageIds, false);

		//preload
		if (!empty($imageIds))
		{
			$this->fileRepository->getByIds($imageIds);
		}

		$entities = [];
		foreach ($groups as $group)
		{
			$workgroup = new Workgroup($group);

			$entity = $this->groupMapper->mapToEntity($workgroup, $this->getImage($workgroup));

			$entities[] = $entity;
		}

		return new Entity\GroupCollection(...$entities);
	}

	public function getGroupIdsByTaskIds(array $taskIds): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$recordset = TaskTable::query()
			->setSelect(['ID', 'GROUP_ID'])
			->whereIn('ID', array_map('intval', $taskIds))
			->fetchAll();

		return array_column($recordset, 'GROUP_ID', 'ID');
	}

	public function getDefaultCollab(int $userId): ?Entity\Group
	{
		$isCollaber = User::isCollaber($userId);
		if (!$isCollaber)
		{
			return null;
		}

		$defaultCollab = CollabDefaultProvider::getInstance()?->getCollab($userId);
		if ($defaultCollab === null)
		{
			return null;
		}

		return $this->groupMapper->mapToEntity($defaultCollab, $this->getImage($defaultCollab));
	}

	private function getImage(Workgroup $workgroup): ?Entity\File
	{
		if ($workgroup->getImageId() > 0)
		{
			return $this->fileRepository->getById($workgroup->getImageId());
		}

		return new Entity\File(
			src: $workgroup->getAvatarUrl(),
		);
	}
}
