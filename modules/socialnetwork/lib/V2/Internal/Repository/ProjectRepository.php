<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\AllowGuestsInvitationField;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\CanGuestCopyTextOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\CanGuestScreenshotOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ManageMessagesAutoDelete;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ManageMessagesOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\MessagesAutoDeleteDelay;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ShowHistoryOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\WhoCanInviteOption;
use Bitrix\Socialnetwork\Internals\Group\GroupEntityCollection;
use Bitrix\Socialnetwork\Collab\Permission\UserRole;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\FeatureTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\File;
use Bitrix\Socialnetwork\V2\Internal\Entity\FileCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Permission;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\PermissionCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service\ExtranetUserService;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ProjectMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\Workgroup\WorkgroupQueryTrait;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureStatesOnConvertService;
use Bitrix\Socialnetwork\V2\Internal\Service\NameService;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Service\StructureRelationService;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\HasCollabersService;
use Bitrix\Socialnetwork\WorkgroupSiteTable;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Socialnetwork\WorkgroupTagTable;

class ProjectRepository implements ProjectRepositoryInterface
{
	use WorkgroupQueryTrait;

	private const CACHE_TTL = 10;

	private const DEFAULT_PERMISSIONS = [
		'calendar' => [],
		'chat' => [],
		'files' => [],
		'tasks' => [
			'view' => UserRole::MEMBER,
			'view_all' => UserRole::MEMBER,
			'sort' => UserRole::MEMBER,
			'create_tasks' => UserRole::MEMBER,
			'edit_tasks' => UserRole::MODERATOR,
			'delete_tasks' => UserRole::MODERATOR,
		],
		'blog' => [
			'view_post' => UserRole::MEMBER,
			'premoderate_post' => UserRole::MEMBER,
			'write_post' => UserRole::MEMBER,
			'moderate_post' => UserRole::MODERATOR,
			'full_post' => UserRole::OWNER,
			'view_comment' => UserRole::MEMBER,
			'premoderate_comment' => UserRole::MEMBER,
			'write_comment' => UserRole::MEMBER,
			'moderate_comment' => UserRole::MODERATOR,
			'full_comment' => UserRole::MODERATOR,
		],
		'landing_knowledge' => [
			'read' => UserRole::MEMBER,
			'edit' => UserRole::MEMBER,
			'sett' => UserRole::MEMBER,
			'delete' => UserRole::MEMBER,
		],
	];

	private const PROJECT_OPTION_NAME_MAP = [
		WhoCanInviteOption::DB_NAME => WhoCanInviteOption::NAME,
		ManageMessagesOption::DB_NAME => ManageMessagesOption::NAME,
		ManageMessagesAutoDelete::DB_NAME => ManageMessagesAutoDelete::NAME,
		MessagesAutoDeleteDelay::DB_NAME => MessagesAutoDeleteDelay::NAME,
		ShowHistoryOption::DB_NAME => ShowHistoryOption::NAME,
		CanGuestCopyTextOption::DB_NAME => CanGuestCopyTextOption::NAME,
		CanGuestScreenshotOption::DB_NAME => CanGuestScreenshotOption::NAME,
		AllowGuestsInvitationField::DB_NAME => AllowGuestsInvitationField::NAME,
	];

	public function __construct(
		private readonly ProjectMapper $projectMapper,
		private readonly NameService $nameService,
		private readonly StructureRelationService $structureRelationService,
		private readonly ExtranetUserService $extranetUserService,
		private readonly CollabOptionRepository $collabOptionRepository,
		private readonly FeatureStatesOnConvertService $featureStatesOnConvertService,
		private readonly FileRepositoryInterface $fileRepository,
	)
	{
	}

	public function getById(int $projectId): ?Project
	{
		if ($projectId <= 0)
		{
			return null;
		}

		$query = WorkgroupTable::query()
			->setSelect([
				'ID',
				'NAME',
				'DESCRIPTION',
				'GOAL',
				'OWNER_ID',
				'VISIBLE',
				'OPENED',
				'CLOSED',
				'DATE_CREATE',
				'DATE_ACTIVITY',
				'NUMBER_OF_MEMBERS',
				'PROJECT_DATE_START',
				'PROJECT_DATE_FINISH',
				'IMAGE_ID',
				'LANDING',
			])
			->where('ID', $projectId)
		;

		$groupRow = $query->exec()->fetch();

		if (!is_array($groupRow))
		{
			return null;
		}

		$tags = $this->getTagsByGroupId($projectId);
		$options = $this->getOptionsByGroupId($projectId);
		$permissions = $this->getPermissionsByGroupId($projectId);
		[$members, $moderatorMembers] = $this->getMembersByGroupId($projectId);
		$hasCollabers = $this->getHasCollabers($projectId);
		$image = $this->findImageById($this->extractImageId($groupRow));

		return $this->projectMapper->mapFromOrmRow(
			groupRow: $groupRow,
			tags: $tags,
			permissions: $permissions,
			members: $members,
			moderatorMembers: $moderatorMembers,
			hasCollabers: $hasCollabers,
			image: $image,
			options: $options,
		);
	}

	public function getSiteIds(int $projectId): array
	{
		if ($projectId <= 0)
		{
			return [];
		}

		$rows = WorkgroupSiteTable::getList([
			'filter' => ['=GROUP_ID' => $projectId],
			'select' => ['SITE_ID'],
		])->fetchAll();

		$siteIds = array_values(array_unique(array_filter(
			array_map(
				static fn(array $row): ?string => is_string($row['SITE_ID'] ?? null) ? $row['SITE_ID'] : null,
				$rows,
			),
			static fn(?string $siteId): bool => $siteId !== null && $siteId !== '',
		)));

		if ($siteIds !== [])
		{
			return $siteIds;
		}

		$groupRow = WorkgroupTable::query()
			->setSelect(['SITE_ID'])
			->where('ID', $projectId)
			->exec()
			->fetch()
		;

		$siteId = $groupRow['SITE_ID'] ?? null;

		return is_string($siteId) && $siteId !== ''
			? [$siteId]
			: []
		;
	}

	/**
	 * @param string[] $select
	 * @param ConditionTree|null $filter
	 * @param array<string, string> $sort
	 * @param int|null $offset
	 * @param int|null $limit
	 * @return ProjectCollection
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getList(
		array $select = ['*'],
		?ConditionTree $filter = null,
		array $sort = [],
		?int $offset = null,
		?int $limit = null,
		bool $withImage = false,
	): ProjectCollection
	{
		$query = $this->createListQuery(
			select: $select,
			filter: $filter,
			sort: $sort,
			offset: $offset,
			limit: $limit,
			currentUserId: null,
			contextUserId: null,
			isAdmin: false,
		);

		$rows = $query->exec()->fetchAll();
		$files = $withImage
			? $this->loadFilesByRows($rows)
			: new FileCollection()
		;

		$projects = [];
		foreach ($rows as $row)
		{
			$file = $this->findFileForRow($row, $files);
			$projects[] = $this->projectMapper->mapFromOrmRow(
				groupRow: $row,
				tags: null,
				permissions: null,
				image: $withImage ? $file : null,
			);
		}

		return new ProjectCollection(...$projects);
	}

	public function getListRaw(
		array $select = ['*'],
		?ConditionTree $filter = null,
		array $sort = [],
		?int $offset = null,
		?int $limit = null,
		?int $currentUserId = null,
		?int $contextUserId = null,
		bool $isAdmin = false,
		?int $pinUserId = null,
		WorkgroupPinMode $pinMode = WorkgroupPinMode::Common,
	): GroupEntityCollection
	{
		$query = $this->createListQuery(
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

		return $query->exec()->fetchCollection();
	}

	public function getCount(
		?ConditionTree $filter = null,
		?int $currentUserId = null,
		bool $isAdmin = false,
	): int
	{
		$query = $this->createCountQuery(
			filter: $filter,
			currentUserId: $currentUserId,
			isAdmin: $isAdmin,
		);

		return $query->exec()->getCount();
	}

	public function getProjectsWithSummaryAgent(?int $offset = null, ?int $limit = null): ProjectCollection
	{
		$enabledProjectsQuery = $this->collabOptionRepository->getProjectSummaryAgentOptionFilter();

		return $this->getList(
			filter: Query::filter()->whereIn('ID', $enabledProjectsQuery),
			sort: ['ID' => 'ASC'],
			offset: $offset,
			limit: $limit,
		);
	}

	private function findImageById(int $imageId): ?File
	{
		if ($imageId <= 0)
		{
			return null;
		}

		return $this->fileRepository->getById($imageId);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function loadFilesByRows(array $rows): FileCollection
	{
		$imageIds = [];
		foreach ($rows as $row)
		{
			$imageId = $this->extractImageId($row);
			if ($imageId > 0)
			{
				$imageIds[] = $imageId;
			}
		}

		return $this->fileRepository->getByIds($imageIds);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function findFileForRow(array $row, FileCollection $files): ?File
	{
		$imageId = $this->extractImageId($row);

		return $imageId > 0 ? $files->findOneById($imageId) : null;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function extractImageId(array $row): int
	{
		return is_numeric($row['IMAGE_ID'] ?? null) ? (int)$row['IMAGE_ID'] : 0;
	}

	protected function applyTypeFilter(Query $query): void
	{
		$query->whereNot('TYPE', Type::Scrum->value);
	}

	protected function getExtranetUserService(): ExtranetUserService
	{
		return $this->extranetUserService;
	}

	private function getTagsByGroupId(int $groupId): array
	{
		$rows = WorkgroupTagTable::getList([
			'filter' => ['=GROUP_ID' => $groupId],
			'select' => ['NAME'],
			'order' => ['NAME' => 'ASC'],
		])->fetchAll();

		if (!is_array($rows) || empty($rows))
		{
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static fn(array $row): ?string => is_string($row['NAME'] ?? null) ? $row['NAME'] : null,
					$rows
				),
				static fn(?string $tag): bool => $tag !== null && $tag !== ''
			)
		);
	}

	private function getPermissionsByGroupId(int $groupId): PermissionCollection
	{
		$featureCollection = FeatureTable::query()
			->setSelect(['ID', 'FEATURE', 'PERMISSIONS.OPERATION_ID', 'PERMISSIONS.ROLE'])
			->where('ENTITY_ID', $groupId)
			->where('ENTITY_TYPE', FeatureTable::FEATURE_ENTITY_TYPE_GROUP)
			->where('ACTIVE', 'Y')
			->setCacheTtl(self::CACHE_TTL)
			->exec()
			->fetchCollection();

		$result = [];

		foreach ($featureCollection as $feature)
		{
			$featureName = $feature->getFeature();

			if (!is_string($featureName) || $featureName === '')
			{
				continue;
			}

			$featurePermissions = [];
			foreach ($feature->getPermissions() as $permission)
			{
				$operationId = $permission->getOperationId();
				$role = $permission->getRole();

				if (!is_string($operationId) || $operationId === '')
				{
					continue;
				}

				$featurePermissions[$operationId] = is_string($role) ? $role : '';
			}

			if ($featurePermissions === [])
			{
				$featurePermissions = $this->getDefaultPermissions($featureName);
			}

			$result[] = new Permission(
				feature: $featureName,
				permissions: $featurePermissions,
			);
		}

		return new PermissionCollection(...$result);
	}

	/**
	 * @return array<string, string>
	 */
	private function getOptionsByGroupId(int $groupId): array
	{
		$this->featureStatesOnConvertService->getOrCreateForConvertedProject($groupId);

		$options = $this->collabOptionRepository->getRawOptions(
			collabId: $groupId,
			optionNames: array_keys(self::PROJECT_OPTION_NAME_MAP),
		);

		$result = [];
		foreach (self::PROJECT_OPTION_NAME_MAP as $dbName => $optionName)
		{
			if (!array_key_exists($dbName, $options))
			{
				continue;
			}

			$result[$optionName] = $options[$dbName];
		}

		return $result;
	}

	private function getDefaultPermissions(string $featureId): array
	{
		return self::DEFAULT_PERMISSIONS[$featureId] ?? [];
	}

	/**
	 * @return array{MemberEntityCollection, MemberEntityCollection}
	 */
	private function getMembersByGroupId(int $groupId): array
	{
		$rows = UserToGroupTable::getList([
			'filter' => [
				'=GROUP_ID' => $groupId,
				'@ROLE' => UserToGroupTable::getRolesMember(),
				// New structure-initiated members have INITIATED_BY_TYPE='S' and legacy members have AUTO_MEMBER='Y'
				'!=INITIATED_BY_TYPE' => UserToGroupTable::INITIATED_BY_STRUCTURE,
			],
			'select' => ['USER_ID', 'ROLE', 'AUTO_MEMBER'],
		])->fetchAll();

		$userIds = array_map(static fn(array $row): int => (int)$row['USER_ID'], $rows);
		$userNames = $this->loadUserNames($userIds);

		$roleMap = [];
		$autoMemberMap = [];
		foreach ($rows as $row)
		{
			$userId = (int)$row['USER_ID'];

			$roleMap[$userId] = $row['ROLE'] ?? '';
			$autoMemberMap[$userId] = ($row['AUTO_MEMBER'] ?? 'N') === 'Y';
		}

		$members = new MemberEntityCollection();
		$moderators = new MemberEntityCollection();

		foreach ($userIds as $userId)
		{
			$entity = new MemberEntity(
				id: $userId,
				type: MemberEntityType::User,
				name: $userNames[$userId] ?? null,
			);

			// Legacy auto-members are represented via linked departments, not as individual members.
			$isAutoMember = $autoMemberMap[$userId] ?? false;
			if (!$isAutoMember)
			{
				$members->add($entity);
			}

			// Moderator role is meaningful even for auto-members, so report it regardless of AUTO_MEMBER.
			if (($roleMap[$userId] ?? '') === UserToGroupTable::ROLE_MODERATOR)
			{
				$moderators->add($entity);
			}
		}

		$members->merge($this->structureRelationService->getLinkedDepartments($groupId));

		return [$members, $moderators];
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, string>
	 */
	private function loadUserNames(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$rows = UserTable::getList([
			'filter' => ['=ID' => $userIds],
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'TITLE'],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$name = $this->nameService->format($row);
			if ($name !== '')
			{
				$result[(int)$row['ID']] = $name;
			}
		}

		return $result;
	}

	private function getHasCollabers(int $projectId): bool
	{
		$batch = $this->getHasCollabersBatch([$projectId]);

		return $batch[$projectId] ?? false;
	}

	/**
	 * @param int[] $projectIds
	 * @return array<int, bool>
	 */
	public function getHasCollabersBatch(array $projectIds): array
	{
		return $this->collabOptionRepository->getOptionBatch(
			collabIds: $projectIds,
			optionName: HasCollabersService::OPTION_NAME,
		);
	}

	/**
	 * @param int[] $groupIds
	 * @return array<int, string[]>
	 */
	public function getTagsByGroupIds(array $groupIds): array
	{
		return $this->loadTagsByGroupIds($groupIds);
	}

	public function getRelationDates(array $groupIds, int $userId): array
	{
		return $this->loadRelationDates($groupIds, $userId);
	}

	public function getViewDates(array $groupIds, int $userId): array
	{
		return $this->loadViewDates($groupIds, $userId);
	}

	public function getGroupIdsByTypes(array $types, int $lastId, int $limit): array
	{
		return WorkgroupTable::query()
			->setSelect(['ID'])
			->whereIn('TYPE', $types)
			->where('ID', '>', $lastId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->exec()
			->fetchAll()
		;
	}

	public function getNameAndType(int $projectId): ?array
	{
		$row =
			WorkgroupTable::query()
				->setSelect(['NAME', 'TYPE', 'PROJECT', 'SCRUM_MASTER_ID'])
				->where('ID', $projectId)
				->exec()
				->fetch()
		;

		if (!is_array($row))
		{
			return null;
		}

		return [
			'name' => $row['NAME'] ?? null,
			'type' => $this->resolveType($row)?->value,
		];
	}

	/**
	 * @see \Bitrix\Socialnetwork\Provider\GroupProvider::getTypeByFields()
	 */
	private function resolveType(array $row): ?Type
	{
		if (isset($row['TYPE']))
		{
			return Type::tryFrom($row['TYPE']);
		}

		if (isset($row['SCRUM_MASTER_ID']))
		{
			return Type::Scrum;
		}

		if (($row['PROJECT'] ?? 'N') === 'Y')
		{
			return Type::Project;
		}

		return Type::getDefault();
	}
}
