<?php

namespace Bitrix\Mobile\Internal\Dto\Project;

final class ProjectCreateDto
{
	public function __construct(
		public readonly string $name = '',
		public readonly string $description = '',
		public readonly ?array $image = null,
		public readonly string $type = 'public',
		public readonly string $initiatePerms = SONET_ROLES_USER,
		public readonly ?string $dateStart = null,
		public readonly ?string $dateFinish = null,
		public readonly array $tags = [],
		public readonly int $ownerId = 0,
		public readonly array $moderatorIds = [],
		public readonly array $participantUserIds = [],
		public readonly array $participantDepartmentIds = [],
		public readonly ?string $messageWriters = null,
		public readonly ?string $showHistory = null,
		public readonly ?int $messagesAutoDeleteDelay = null,
		public readonly string $taskViewAll = SONET_ROLES_USER,
		public readonly string $taskSort = SONET_ROLES_USER,
		public readonly string $taskCreateTasks = SONET_ROLES_USER,
		public readonly string $taskEditTasks = SONET_ROLES_USER,
		public readonly string $taskDeleteTasks = SONET_ROLES_USER,
		public readonly string $knowledgeRead = SONET_ROLES_USER,
		public readonly string $knowledgeEdit = SONET_ROLES_USER,
		public readonly string $knowledgeSettings = SONET_ROLES_USER,
		public readonly string $knowledgeDelete = SONET_ROLES_USER,
		public readonly ?array $avatar = null,
	)
	{
	}

	public static function fromArray(array $fields): self
	{
		$taskPermissions = is_array($fields['taskPermissions'] ?? null) ? $fields['taskPermissions'] : [];
		$knowledgePermissions = is_array($fields['knowledgePermissions'] ?? null) ? $fields['knowledgePermissions'] : [];
		$tags = self::normalizeTags($fields['tags'] ?? []);

		return new self(
			name: (string)($fields['name'] ?? ''),
			description: (string)($fields['description'] ?? ''),
			image: is_array($fields['image'] ?? null) ? $fields['image'] : null,
			type: (string)($fields['type'] ?? 'public'),
			initiatePerms: (string)($fields['initiatePerms'] ?? SONET_ROLES_USER),
			dateStart: isset($fields['dateStart']) ? (string)$fields['dateStart'] : null,
			dateFinish: isset($fields['dateFinish']) ? (string)$fields['dateFinish'] : null,
			tags: $tags,
			ownerId: (int)($fields['ownerId'] ?? 0),
			moderatorIds: self::normalizeIdList($fields['moderatorIds'] ?? []),
			participantUserIds: self::normalizeIdList($fields['participantUserIds'] ?? []),
			participantDepartmentIds: self::normalizeIdList($fields['participantDepartmentIds'] ?? []),
			messageWriters: isset($fields['messageWriters']) ? (string)$fields['messageWriters'] : null,
			showHistory: isset($fields['showHistory']) ? (string)$fields['showHistory'] : null,
			messagesAutoDeleteDelay: isset($fields['messagesAutoDeleteDelay'])
				? (int)$fields['messagesAutoDeleteDelay']
				: null,
			taskViewAll: (string)($taskPermissions['viewAll'] ?? SONET_ROLES_USER),
			taskSort: (string)($taskPermissions['sort'] ?? SONET_ROLES_USER),
			taskCreateTasks: (string)($taskPermissions['createTasks'] ?? SONET_ROLES_USER),
			taskEditTasks: (string)($taskPermissions['editTasks'] ?? SONET_ROLES_USER),
			taskDeleteTasks: (string)($taskPermissions['deleteTasks'] ?? SONET_ROLES_USER),
			knowledgeRead: (string)($knowledgePermissions['read'] ?? SONET_ROLES_USER),
			knowledgeEdit: (string)($knowledgePermissions['edit'] ?? SONET_ROLES_USER),
			knowledgeSettings: (string)($knowledgePermissions['settings'] ?? SONET_ROLES_USER),
			knowledgeDelete: (string)($knowledgePermissions['delete'] ?? SONET_ROLES_USER),
			avatar: self::normalizeAvatar(
				$fields['avatar'] ?? null,
				$fields['avatarId'] ?? null,
				$fields['image'] ?? null,
			),
		);
	}

	private static function normalizeIdList(mixed $items): array
	{
		if (!is_array($items))
		{
			return [];
		}

		$result = [];

		foreach ($items as $item)
		{
			$id = (int)$item;
			if ($id <= 0 || isset($result[$id]))
			{
				continue;
			}

			$result[$id] = $id;
		}

		return array_values($result);
	}

	private static function normalizeTags(mixed $items): array
	{
		if (!is_array($items))
		{
			return [];
		}

		$result = [];

		foreach ($items as $item)
		{
			$tag = trim((string)$item);
			if ($tag === '')
			{
				continue;
			}

			$key = mb_strtolower($tag);
			if (isset($result[$key]))
			{
				continue;
			}

			$result[$key] = $tag;
		}

		return array_values($result);
	}

	private static function normalizeAvatar(mixed $avatar, mixed $avatarId, mixed $image): ?array
	{
		if (is_array($avatar))
		{
			$normalizedAvatar = [];

			$avatarIdValue = self::extractPositiveInt($avatar['id'] ?? ($avatar['file']['id'] ?? null));
			if ($avatarIdValue !== null)
			{
				$normalizedAvatar['id'] = $avatarIdValue;
			}

			if (array_key_exists('encodedFile', $avatar) && is_string($avatar['encodedFile']))
			{
				$normalizedAvatar['encodedFile'] = $avatar['encodedFile'];
			}
			elseif (is_string($avatar['base64'] ?? null))
			{
				$normalizedAvatar['encodedFile'] = $avatar['base64'];
			}

			if ($normalizedAvatar !== [])
			{
				return $normalizedAvatar;
			}
		}

		if (is_string($avatarId))
		{
			return ['encodedFile' => $avatarId];
		}

		if (!is_array($image))
		{
			return null;
		}

		$imageId = self::extractPositiveInt($image['id'] ?? ($image['file']['id'] ?? null));
		if ($imageId !== null)
		{
			return ['id' => $imageId];
		}

		if (is_string($image['base64'] ?? null))
		{
			return ['encodedFile' => $image['base64']];
		}

		if (is_string($image[1] ?? null))
		{
			return ['encodedFile' => $image[1]];
		}

		return null;
	}

	private static function extractPositiveInt(mixed $value): ?int
	{
		if (!is_numeric($value))
		{
			return null;
		}

		$intValue = (int)$value;

		return $intValue > 0 ? $intValue : null;
	}
}
