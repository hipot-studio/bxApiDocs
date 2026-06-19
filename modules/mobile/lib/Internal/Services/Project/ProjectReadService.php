<?php

namespace Bitrix\Mobile\Internal\Services\Project;

use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;

final class ProjectReadService
{
	private const PROJECT_TYPE_PUBLIC = 'public';
	private const PROJECT_TYPE_PRIVATE = 'private';

	private const PRIVACY_TYPE_CLOSED = 'closed';

	private const MEMBER_TYPE_USER = 'user';
	private const MEMBER_TYPE_DEPARTMENT = 'department';

	private const FEATURE_TASKS = 'tasks';
	private const FEATURE_KNOWLEDGE = 'landing_knowledge';

	private const TASK_OPERATION_VIEW = 'view';
	private const TASK_OPERATION_SORT = 'sort';
	private const TASK_OPERATION_CREATE = 'create_tasks';
	private const TASK_OPERATION_EDIT = 'edit_tasks';
	private const TASK_OPERATION_DELETE = 'delete_tasks';

	private const KNOWLEDGE_OPERATION_READ = 'read';
	private const KNOWLEDGE_OPERATION_EDIT = 'edit';
	private const KNOWLEDGE_OPERATION_SETTINGS = 'sett';
	private const KNOWLEDGE_OPERATION_DELETE = 'delete';

	private readonly ProjectProvider $projectProvider;

	public function __construct(
		private readonly int $currentUserId,
		private readonly ProjectChatSettingsService $projectChatSettingsService,
		?ProjectProvider $projectProvider = null,
	)
	{
		$this->projectProvider = $projectProvider ?? new ProjectProvider();
	}

	public function getCurrentUserData(): array
	{
		return $this->getUserItems([$this->currentUserId])[0] ?? [
			'id' => $this->currentUserId,
			'title' => '',
			'imageUrl' => '',
		];
	}

	public function getEditSettings(int $projectId): array
	{
		$project = $this->projectProvider->getById($projectId);
		if ($project === null)
		{
			throw new \RuntimeException('Failed to load project edit settings.');
		}

		$projectData = $project->toArray();
		$workgroup = Workgroup::getById($projectId);
		$workgroupFields = $workgroup?->getFields() ?? [];
		$image = $this->resolveImage($projectData['avatar'] ?? null, $workgroupFields);

		return [
			'name' => (string)($projectData['name'] ?? ''),
			'description' => (string)($projectData['description'] ?? ''),
			'image' => $image,
			'isLegacyProject' => $this->isLegacyProject($projectId),
			'ownerData' => $this->getOwnerData($projectData),
			'moderatorsData' => $this->getModeratorsData($projectData),
			'participants' => $this->getParticipantsData($projectData),
			'type' => $this->mapPrivacyTypeToProjectType($projectData['privacyType'] ?? null),
			'initiatePerms' => $this->getInitiatePerms($projectId),
			'dateStart' => (int)($projectData['dates']['startTs'] ?? 0),
			'dateFinish' => (int)($projectData['dates']['finishTs'] ?? 0),
			'tags' => $this->extractTagNames($projectData['tags'] ?? []),
			...$this->projectChatSettingsService->getSettings($projectId),
			...$this->mapPermissionsToLegacySettings($projectData['permissions'] ?? []),
		];
	}

	private function mapImage(mixed $avatar): ?array
	{
		if (!is_array($avatar))
		{
			return null;
		}

		$previewUrl = is_string($avatar['url'] ?? null) ? $avatar['url'] : '';
		$imageId = (int)($avatar['id'] ?? 0);

		if ($previewUrl === '' && $imageId <= 0)
		{
			return null;
		}

		return array_filter([
			'id' => $imageId > 0 ? $imageId : null,
			'previewUrl' => $previewUrl !== '' ? $this->normalizeUrl($previewUrl) : null,
		], static fn(mixed $value): bool => $value !== null);
	}

	private function resolveImage(mixed $avatar, array $workgroupFields): ?array
	{
		$image = $this->mapImage($avatar);
		if ($image !== null)
		{
			return $image;
		}

		$imageId = (int)($workgroupFields['IMAGE_ID'] ?? 0);
		if ($imageId <= 0)
		{
			return null;
		}

		$path = \CFile::GetPath($imageId);
		if (!is_string($path) || $path === '')
		{
			return null;
		}

		return [
			'id' => $imageId,
			'previewUrl' => $this->normalizeUrl($path),
		];
	}

	private function normalizeUrl(string $url): string
	{
		if (preg_match('#^https?://#i', $url))
		{
			return $url;
		}

		return UrlManager::getInstance()->getHostUrl() . $url;
	}

	private function getOwnerData(array $projectData): array
	{
		$ownerId = (int)($projectData['ownerId'] ?? 0);
		if ($ownerId <= 0)
		{
			return [
				'id' => 0,
				'title' => '',
				'imageUrl' => '',
			];
		}

		$owner = is_array($projectData['owner'] ?? null) ? $projectData['owner'] : [];
		$items = $this->getUserItems(
			[$ownerId],
			[$ownerId => (string)($owner['name'] ?? '')],
			[$ownerId => $this->normalizeOptionalUrl($owner['image']['src'] ?? null)],
		);

		return $items[0] ?? [
			'id' => $ownerId,
			'title' => (string)($owner['name'] ?? ''),
			'imageUrl' => $this->normalizeOptionalUrl($owner['image']['src'] ?? null) ?? '',
		];
	}

	private function getModeratorsData(array $projectData): array
	{
		$moderatorIds = [];

		foreach (($projectData['moderatorMembers'] ?? []) as $member)
		{
			if (($member['type'] ?? null) !== self::MEMBER_TYPE_USER)
			{
				continue;
			}

			$userId = (int)($member['id'] ?? 0);
			if ($userId > 0)
			{
				$moderatorIds[] = $userId;
			}
		}

		return $this->getUserItems($moderatorIds);
	}

	private function getParticipantsData(array $projectData): array
	{
		$ownerId = (int)($projectData['ownerId'] ?? 0);
		$moderatorIds = array_flip(array_map(
			static fn(array $member): int => (int)($member['id'] ?? 0),
			array_filter(
				$projectData['moderatorMembers'] ?? [],
				static fn(mixed $member): bool => is_array($member) && ($member['type'] ?? null) === self::MEMBER_TYPE_USER,
			),
		));

		$userIds = [];
		$departmentIds = [];

		foreach (($projectData['members'] ?? []) as $member)
		{
			if (!is_array($member))
			{
				continue;
			}

			$id = (int)($member['id'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}

			if (($member['type'] ?? null) === self::MEMBER_TYPE_DEPARTMENT)
			{
				$departmentIds[] = $id;

				continue;
			}

			if ($id === $ownerId || isset($moderatorIds[$id]))
			{
				continue;
			}

			$userIds[] = $id;
		}

		return [
			'user' => $this->getUserItems($userIds),
			'department' => $this->getDepartmentItems($departmentIds),
		];
	}

	private function getUserItems(
		array $userIds,
		array $fallbackTitles = [],
		array $fallbackImages = [],
	): array
	{
		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
		if ($userIds === [])
		{
			return [];
		}

		$users = [];
		$userResult = UserTable::getList([
			'filter' => ['@ID' => $userIds],
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'PERSONAL_PHOTO'],
		]);

		while ($user = $userResult->fetch())
		{
			$userId = (int)$user['ID'];
			$imageUrl = '';
			$photoId = (int)($user['PERSONAL_PHOTO'] ?? 0);
			if ($photoId > 0)
			{
				$imageUrl = $this->normalizeOptionalUrl(\CFile::GetPath($photoId)) ?? '';
			}

			$users[$userId] = [
				'id' => $userId,
				'title' => \CUser::FormatName(
					\CSite::getNameFormat(),
					[
						'NAME' => $user['NAME'],
						'LAST_NAME' => $user['LAST_NAME'],
						'SECOND_NAME' => $user['SECOND_NAME'],
						'LOGIN' => $user['LOGIN'],
					],
					ModuleManager::isModuleInstalled('intranet'),
				),
				'imageUrl' => $imageUrl,
			];
		}

		$result = [];
		foreach ($userIds as $userId)
		{
			$item = $users[$userId] ?? [
				'id' => $userId,
				'title' => '',
				'imageUrl' => '',
			];

			if ($item['title'] === '' && is_string($fallbackTitles[$userId] ?? null))
			{
				$item['title'] = $fallbackTitles[$userId];
			}

			if ($item['imageUrl'] === '' && is_string($fallbackImages[$userId] ?? null))
			{
				$item['imageUrl'] = $fallbackImages[$userId];
			}

			$result[] = $item;
		}

		return $result;
	}

	private function getDepartmentItems(array $departmentIds): array
	{
		$departmentIds = array_values(array_unique(array_filter(array_map('intval', $departmentIds))));
		if ($departmentIds === [] || !Loader::includeModule('intranet'))
		{
			return [];
		}

		$departmentsList = \CIntranetUtils::getDepartmentsData($departmentIds);
		if (empty($departmentsList))
		{
			return [];
		}

		$result = [];
		foreach ($departmentIds as $departmentId)
		{
			$department = $departmentsList[$departmentId] ?? null;
			if (!is_array($department))
			{
				continue;
			}

			$result[] = [
				'id' => $departmentId,
				'title' => (string)($department['NAME'] ?? ''),
				'imageUrl' => '',
			];
		}

		return $result;
	}

	private function mapPrivacyTypeToProjectType(?string $privacyType): string
	{
		return $privacyType === self::PRIVACY_TYPE_CLOSED
			? self::PROJECT_TYPE_PRIVATE
			: self::PROJECT_TYPE_PUBLIC;
	}

	private function extractTagNames(array $tags): array
	{
		$result = [];

		foreach ($tags as $tag)
		{
			$name = is_array($tag) ? (string)($tag['name'] ?? '') : '';
			if ($name !== '')
			{
				$result[] = $name;
			}
		}

		return $result;
	}

	private function getInitiatePerms(int $projectId): string
	{
		$workgroup = Workgroup::getById($projectId);
		$permission = $workgroup?->getFields()['INITIATE_PERMS'] ?? SONET_ROLES_USER;

		return $this->normalizeRole($permission);
	}

	private function mapPermissionsToLegacySettings(array $permissions): array
	{
		$permissionsMap = [];

		foreach ($permissions as $permission)
		{
			if (!is_array($permission))
			{
				continue;
			}

			$feature = (string)($permission['feature'] ?? '');
			$operations = is_array($permission['permissions'] ?? null) ? $permission['permissions'] : null;
			if ($feature === '' || $operations === null)
			{
				continue;
			}

			$permissionsMap[$feature] = $operations;
		}

		return [
			'taskViewPerms' => $this->normalizeRole(
				$permissionsMap[self::FEATURE_TASKS][self::TASK_OPERATION_VIEW] ?? null,
			),
			'taskSortPerms' => $this->normalizeRole(
				$permissionsMap[self::FEATURE_TASKS][self::TASK_OPERATION_SORT] ?? null,
			),
			'taskCreatePerms' => $this->normalizeRole(
				$permissionsMap[self::FEATURE_TASKS][self::TASK_OPERATION_CREATE] ?? null,
			),
			'taskEditPerms' => $this->normalizeRole(
				$permissionsMap[self::FEATURE_TASKS][self::TASK_OPERATION_EDIT] ?? null,
			),
			'taskDeletePerms' => $this->normalizeRole(
				$permissionsMap[self::FEATURE_TASKS][self::TASK_OPERATION_DELETE] ?? null,
			),
			'knowledgeViewPerms' => $this->normalizeRole(
				$permissionsMap[self::FEATURE_KNOWLEDGE][self::KNOWLEDGE_OPERATION_READ] ?? null,
			),
			'knowledgeEditPerms' => $this->normalizeRole(
				$permissionsMap[self::FEATURE_KNOWLEDGE][self::KNOWLEDGE_OPERATION_EDIT] ?? null,
			),
			'knowledgeSettingsPerms' => $this->normalizeRole(
				$permissionsMap[self::FEATURE_KNOWLEDGE][self::KNOWLEDGE_OPERATION_SETTINGS] ?? null,
			),
			'knowledgeDeletePerms' => $this->normalizeRole(
				$permissionsMap[self::FEATURE_KNOWLEDGE][self::KNOWLEDGE_OPERATION_DELETE] ?? null,
			),
		];
	}

	private function normalizeRole(mixed $role): string
	{
		return in_array($role, [SONET_ROLES_OWNER, SONET_ROLES_MODERATOR, SONET_ROLES_USER], true)
			? $role
			: SONET_ROLES_USER;
	}

	private function normalizeOptionalUrl(mixed $url): ?string
	{
		return is_string($url) && $url !== ''
			? $this->normalizeUrl($url)
			: null;
	}

	private function isLegacyProject(int $projectId): bool
	{
		return CollabRegistry::getInstance()->get($projectId) === null;
	}
}
