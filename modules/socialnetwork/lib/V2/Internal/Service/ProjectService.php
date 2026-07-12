<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Helper\Path;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Avatar;
use Bitrix\Socialnetwork\V2\Internal\Entity\FileCollection;
use Bitrix\Socialnetwork\V2\Internal\Repository\MemberFilterType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Socialnetwork\V2\Internal\Repository\FileRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ProjectMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberDisplayRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectRepository;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Socialnetwork\Internals\Group\GroupEntity;

class ProjectService
{
	public function __construct(
		private readonly ProjectRepository $projectRepository,
		private readonly ProjectMemberDisplayRepository $projectMemberRepository,
		private readonly FileRepositoryInterface $fileRepository,
		private readonly UserServiceInterface $userService,
		private readonly PhotoService $photoService,
		private readonly ProjectMapper $projectMapper,
	)
	{
	}

	/**
	 * @return \Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectGridRow[]
	 */
	public function getGridList(
		?ConditionTree $filter = null,
		array $sort = [],
		?int $offset = null,
		?int $limit = null,
		array $select = ['*'],
		bool $withImage = false,
		bool $withMembers = false,
		bool $withOwner = false,
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

		$rows = $this->projectRepository->getListRaw(
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
		$projectIds = [];

		foreach ($rows as $row)
		{
			$groupRows[] = $row;
			$projectIds[] = $row->getId();

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
			? $this->projectMemberRepository->getByProjectIds($projectIds)
			: ['members' => [], 'headsCount' => []]
		;
		$membersByProject = $memberData['members'];
		$headsCountByProject = $memberData['headsCount'];

		$ownersById = [];
		$memberOwnersByProject = [];
		if ($withOwner && !$withMembers)
		{
			$ownersById = $this->userService->getUsers($ownerIds)->indexById();
		}
		elseif ($withOwner)
		{
			foreach ($membersByProject as $projectId => $members)
			{
				$memberOwnersByProject[$projectId] = $members->indexById();
			}
		}

		$hasCollabersMap = $this->projectRepository->getHasCollabersBatch($projectIds);

		$tagsByProject = $withTags
			? $this->projectRepository->getTagsByGroupIds($projectIds)
			: [];
		$relationDates = $withRelationDate && $contextUserId > 0
			? $this->projectRepository->getRelationDates($projectIds, $contextUserId)
			: [];
		$viewDates = $withViewDate && $currentUserId > 0
			? $this->projectRepository->getViewDates($projectIds, $currentUserId)
			: [];

		$result = [];
		foreach ($groupRows as $row)
		{
			$image = $row->getImageId() ? $images->findOneById($row->getImageId()) : null;
			$image = $image ? $this->photoService->resize($image) : null;
			$members = $membersByProject[$row->getId()] ?? null;
			$avatar = $image !== null
				? new Avatar(id: $image->id, url: $image->src)
				: null
			;

			$owner = null;
			$ownerId = $row->getOwnerId();
			if ($withOwner && $ownerId > 0)
			{
				$owner = $memberOwnersByProject[$row->getId()][$ownerId] ?? $ownersById[$ownerId] ?? null;
			}

			$project = $this->projectMapper->mapFromOrm(
				group: $row,
				avatar: $avatar,
				owner: $owner,
				numberOfModerators: $headsCountByProject[$row->getId()] ?? null,
				hasCollabers: $hasCollabersMap[$row->getId()] ?? false,
			);

			$result[] = $this->projectMapper->mapToGridRow(
				project: $project,
				members: $members,
				tags: $tagsByProject[$row->getId()] ?? [],
				activityDate: $this->extractActivityDate($row, $select),
				dateRelation: $relationDates[$row->getId()] ?? null,
				dateView: $viewDates[$row->getId()] ?? null,
				scrumMasterId: $row->getScrumMasterId() > 0 ? $row->getScrumMasterId() : null,
				type: $row->getType(),
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

	public function getMembers(int $projectId, string $type = 'all', int $page = 1, int $pageSize = 10): array
	{
		$offset = ($page - 1) * $pageSize;
		$filterType = MemberFilterType::tryFrom($type) ?? MemberFilterType::All;

		$members = $this->projectMemberRepository->getPagedMembers($projectId, $filterType, $pageSize, $offset);

		$pathToUser = Path::get('user_profile');

		$result = [];
		foreach ($members as $user)
		{
			$result[] = [
				'ID' => $user->id,
				'PHOTO' => $user->image?->src ?? '',
				'HREF' => \CComponentEngine::makePathFromTemplate($pathToUser, [
					'user_id' => $user->id,
					'id' => $user->id,
					'ID' => $user->id,
				]),
				'FORMATTED_NAME' => htmlspecialcharsback($user->name ?? ''),
				'ROLE' => $user->role?->value ?? '',
			];
		}

		return $result;
	}

	public function getCount(
		?ConditionTree $filter = null,
		?int $currentUserId = null,
		bool $isAdmin = false,
	): int
	{
		return $this->projectRepository->getCount(
			filter: $filter,
			currentUserId: $currentUserId,
			isAdmin: $isAdmin,
		);
	}
}
