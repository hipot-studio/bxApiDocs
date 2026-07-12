<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Socialnetwork\Internals\Group\GroupEntity;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Entity\FileCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Avatar;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectGridRow;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Socialnetwork\V2\Internal\Repository\FileRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ProjectMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberDisplayRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupRepository;

/**
 * Service for COMMON/USER grid mode.
 * Loads all workgroup types (projects, scrums, collabs, groups) for grid display.
 */
class WorkgroupGridService
{
	public function __construct(
		private readonly WorkgroupRepository $workgroupRepository,
		private readonly ProjectMemberDisplayRepository $projectMemberRepository,
		private readonly FileRepository $fileRepository,
		private readonly PhotoService $photoService,
		private readonly ProjectMapper $projectMapper,
	)
	{
	}

	/**
	 * @return ProjectGridRow[]
	 */
	public function getGridList(
		?ConditionTree $filter = null,
		array $sort = [],
		?int $offset = null,
		?int $limit = null,
		array $select = ['*'],
		bool $withImage = false,
		bool $withMembers = false,
		bool $withTags = false,
		bool $withRelationDate = false,
		bool $withViewDate = false,
		?int $currentUserId = null,
		?int $contextUserId = null,
		bool $isAdmin = false,
		?int $pinUserId = null,
		WorkgroupPinMode $pinMode = WorkgroupPinMode::Common,
	): array
	{
		$contextUserId = (($contextUserId ?? 0) > 0 ? $contextUserId : $currentUserId);

		$rows = $this->workgroupRepository->getListRaw(
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
		$groupIds = [];

		foreach ($rows as $row)
		{
			$groupRows[] = $row;
			$groupIds[] = $row->getId();

			if ($withImage && $row->getImageId() > 0)
			{
				$imageIds[] = $row->getImageId();
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
			? $this->projectMemberRepository->getByProjectIds($groupIds)
			: ['members' => [], 'headsCount' => []]
		;
		$membersByGroup = $memberData['members'];
		$headsCountByGroup = $memberData['headsCount'];

		$tagsByGroup = $withTags
			? $this->workgroupRepository->getTagsByGroupIds($groupIds)
			: [];
		$relationDates = $withRelationDate && $contextUserId > 0
			? $this->workgroupRepository->getRelationDates($groupIds, $contextUserId)
			: [];
		$viewDates = $withViewDate && $currentUserId > 0
			? $this->workgroupRepository->getViewDates($groupIds, $currentUserId)
			: [];

		$result = [];
		foreach ($groupRows as $row)
		{
			$image = $row->getImageId() ? $images->findOneById($row->getImageId()) : null;
			$members = $membersByGroup[$row->getId()] ?? null;
			$image = $image ? $this->photoService->resize($image) : null;
			$avatar = $image !== null
				? new Avatar(id: $image->id, url: $image->src)
				: null
			;

			$project = $this->projectMapper->mapFromOrm(
				group: $row,
				avatar: $avatar,
				numberOfModerators: $headsCountByGroup[$row->getId()] ?? null,
			);

			$result[] = $this->projectMapper->mapToGridRow(
				project: $project,
				members: $members,
				tags: $tagsByGroup[$row->getId()] ?? [],
				dateRelation: $relationDates[$row->getId()] ?? null,
				dateView: $viewDates[$row->getId()] ?? null,
				scrumMasterId: $row->getScrumMasterId() > 0 ? $row->getScrumMasterId() : null,
				type: $row->getType(),
				privacyType: $this->getGridPrivacyType($row),
			);
		}

		return $result;
	}

	public function getCount(
		?ConditionTree $filter = null,
		?int $currentUserId = null,
		bool $isAdmin = false,
	): int
	{
		return $this->workgroupRepository->getCount(
			filter: $filter,
			currentUserId: $currentUserId,
			isAdmin: $isAdmin,
		);
	}

	private function getGridPrivacyType(GroupEntity $group): ?string
	{
		if ($group->getType() !== Type::Scrum->value)
		{
			return null;
		}

		return $group->getVisible() === false ? PrivacyType::LEGACY_SCRUM_SECRET : null;
	}
}
