<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Internals\Group\GroupEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\FileCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Scrum\ScrumGridRow;
use Bitrix\Socialnetwork\V2\Internal\Repository\FileRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ScrumMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberDisplayRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ScrumRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;

class ScrumService
{
	public function __construct(
		private readonly ScrumRepositoryInterface $scrumRepository,
		private readonly ProjectMemberDisplayRepository $memberRepository,
		private readonly FileRepositoryInterface $fileRepository,
		private readonly UserServiceInterface $userService,
		private readonly PhotoService $photoService,
		private readonly ScrumMapper $scrumMapper,
	)
	{
	}

	/**
	 * @return ScrumGridRow[]
	 */
	public function getList(
		?ConditionTree $filter = null,
		array $sort = [],
		?int $offset = null,
		?int $limit = null,
		array $select = ['*'],
		bool $withImage = false,
		bool $withMembers = false,
		bool $withOwner = false,
		?int $currentUserId = null,
		?int $contextUserId = null,
		bool $isAdmin = false,
		?int $pinUserId = null,
		WorkgroupPinMode $pinMode = WorkgroupPinMode::Common,
	): array
	{
		$contextUserId = (($contextUserId ?? 0) > 0 ? $contextUserId : $currentUserId);

		$rows = $this->scrumRepository->getListRaw(
			select: $select,
			filter: $filter,
			sort: $sort,
			offset: $offset,
			limit: $limit,
			currentUserId: $currentUserId,
			contextUserId: $contextUserId,
			isAdmin: $isAdmin,
			pinUserId: $pinUserId,
			pinMode: $pinMode,
		);

		$groupRows = [];
		$imageIds = [];
		$ownerIds = [];
		$scrumIds = [];

		foreach ($rows as $row)
		{
			$groupRows[] = $row;
			$scrumIds[] = $row->getId();

			if ($withImage && $row->getImageId() > 0)
			{
				$imageIds[] = $row->getImageId();
			}

			if ($withOwner && $row->getOwnerId() > 0)
			{
				$ownerIds[] = $row->getOwnerId();
			}
		}

		if (empty($groupRows))
		{
			return [];
		}

		$images = $withImage
			? $this->fileRepository->getByIds($imageIds)
			: new FileCollection()
		;

		$memberData = $withMembers
			? $this->memberRepository->getByProjectIds($scrumIds)
			: ['members' => [], 'headsCount' => []]
		;
		$membersByScrum = $memberData['members'];
		$headsCountByScrum = $memberData['headsCount'];

		$ownersById = [];
		$memberOwnersByScrum = [];
		if ($withOwner && !$withMembers)
		{
			$ownersById = $this->userService->getUsers($ownerIds)->indexById();
		}
		elseif ($withOwner)
		{
			foreach ($membersByScrum as $scrumId => $members)
			{
				$memberOwnersByScrum[$scrumId] = $members->indexById();
			}
		}

		$tagsByScrum = $this->scrumRepository->getTagsByGroupIds($scrumIds);
		$relationDates = $contextUserId > 0
			? $this->scrumRepository->getRelationDates($scrumIds, $contextUserId)
			: [];
		$viewDates = $currentUserId > 0
			? $this->scrumRepository->getViewDates($scrumIds, $currentUserId)
			: [];

		$result = [];
		foreach ($groupRows as $row)
		{
			$image = $row->getImageId() ? $images->findOneById($row->getImageId()) : null;
			$members = $membersByScrum[$row->getId()] ?? null;

			$owner = null;
			$ownerId = $row->getOwnerId();
			if ($withOwner && $ownerId > 0)
			{
				$owner = $memberOwnersByScrum[$row->getId()][$ownerId] ?? $ownersById[$ownerId] ?? null;
			}

			$result[] = $this->scrumMapper->mapToGridRow(
				group: $row,
				image: $image ? $this->photoService->resize($image) : null,
				owner: $owner,
				members: $members,
				numberOfModerators: $headsCountByScrum[$row->getId()] ?? null,
				tags: $tagsByScrum[$row->getId()] ?? [],
				activityDate: $this->extractActivityDate($row, $select),
				dateRelation: $relationDates[$row->getId()] ?? null,
				dateView: $viewDates[$row->getId()] ?? null,
			);
		}

		return $result;
	}

	private function extractActivityDate(GroupEntity $row, array $select): ?string
	{
		if (!in_array('ACTIVITY_DATE', $select, true))
		{
			return null;
		}

		$value = $row->get('ACTIVITY_DATE');

		if ($value instanceof DateTime)
		{
			return $value->toString();
		}

		return is_string($value) && $value !== '' ? $value : null;
	}

	public function getCount(
		?ConditionTree $filter = null,
		?int $currentUserId = null,
		bool $isAdmin = false,
	): int
	{
		return $this->scrumRepository->getCount(
			filter: $filter,
			currentUserId: $currentUserId,
			isAdmin: $isAdmin,
		);
	}
}
