<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Collection;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ChatColorResolver;
use Bitrix\Socialnetwork\WorkgroupTable;

class ProjectChatDataProvider
{
	private const CHAT_ENTITY_TYPE = 'SONET_GROUP';

	/**
	 * @var array<string, array<int, array{chatId: ?int, color: ?string}>>
	 */
	private array $cache = [];

	public function __construct(
		private readonly ChatColorResolver $chatColorResolver,
	)
	{
	}

	/**
	 * @param int[] $projectIds
	 * @return array<int, array{chatId: ?int, color: ?string}>
	 */
	public function getByProjectIds(array $projectIds): array
	{
		if ($projectIds === [] || !$this->isAvailable())
		{
			return [];
		}

		Collection::normalizeArrayValuesByInt($projectIds, false);
		$projectIds = array_values(array_unique(array_filter(
			$projectIds,
			static fn (int $projectId): bool => $projectId > 0,
		)));

		if ($projectIds === [])
		{
			return [];
		}

		$cacheKey = serialize($projectIds);
		if (isset($this->cache[$cacheKey]))
		{
			return $this->cache[$cacheKey];
		}

		$result = [];
		foreach ($projectIds as $projectId)
		{
			$result[$projectId] = [
				'chatId' => null,
				'color' => null,
			];
		}

		$res = ChatTable::getList([
			'select' => ['ID', 'ENTITY_ID', 'COLOR'],
			'filter' => [
				'=ENTITY_TYPE' => self::CHAT_ENTITY_TYPE,
				'@ENTITY_ID' => $projectIds,
			],
		]);

		while ($chat = $res->fetch())
		{
			$projectId = (int)($chat['ENTITY_ID'] ?? 0);
			if ($projectId <= 0 || !array_key_exists($projectId, $result))
			{
				continue;
			}

			$chatId = (int)($chat['ID'] ?? 0);
			$result[$projectId] = [
				'chatId' => $chatId > 0 ? $chatId : null,
				'color' => $this->chatColorResolver->resolve(
					$chatId,
					(string)($chat['COLOR'] ?? ''),
				),
			];
		}

		$this->cache[$cacheKey] = $result;

		return $result;
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, int> map chatId => projectId (only resolved project chats)
	 */
	public function getProjectIdsByChatIds(array $chatIds): array
	{
		if ($chatIds === [] || !$this->isAvailable())
		{
			return [];
		}

		Collection::normalizeArrayValuesByInt($chatIds, false);
		$chatIds = array_values(array_unique(array_filter(
			$chatIds,
			static fn (int $chatId): bool => $chatId > 0,
		)));

		if ($chatIds === [])
		{
			return [];
		}

		return $this->resolveProjectIds($chatIds);
	}

	public function getProjectIdByChatId(int $chatId): ?int
	{
		if ($chatId <= 0 || !$this->isAvailable())
		{
			return null;
		}

		return $this->resolveProjectIds([$chatId])[$chatId] ?? null;
	}

	/**
	 * Unlike getProjectIdByChatId(), the group is returned whatever its type is.
	 */
	public function getGroupIdByChatId(int $chatId): ?int
	{
		if ($chatId <= 0 || !$this->isAvailable())
		{
			return null;
		}

		return $this->getGroupIdsByChatIds([$chatId])[$chatId] ?? null;
	}

	/**
	 * Project is resolved only for a chat whose group is actually a collab.
	 *
	 * @param int[] $chatIds
	 * @return array<int, int> map chatId => projectId
	 */
	private function resolveProjectIds(array $chatIds): array
	{
		$groupIdByChatId = $this->getGroupIdsByChatIds($chatIds);
		if ($groupIdByChatId === [])
		{
			return [];
		}

		$collabIds = $this->getCollabIds(array_values($groupIdByChatId));

		return array_filter(
			$groupIdByChatId,
			static fn (int $groupId): bool => isset($collabIds[$groupId]),
		);
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, int> map chatId => groupId
	 */
	private function getGroupIdsByChatIds(array $chatIds): array
	{
		$result = [];

		$res = ChatTable::getList([
			'select' => ['ID', 'ENTITY_ID'],
			'filter' => [
				'=ENTITY_TYPE' => self::CHAT_ENTITY_TYPE,
				'@ID' => $chatIds,
			],
		]);

		while ($chat = $res->fetch())
		{
			$chatId = (int)($chat['ID'] ?? 0);
			$groupId = (int)($chat['ENTITY_ID'] ?? 0);
			if ($chatId > 0 && $groupId > 0)
			{
				$result[$chatId] = $groupId;
			}
		}

		return $result;
	}

	/**
	 * Type is read from the table to avoid relying on a cached group snapshot.
	 *
	 * @param int[] $groupIds
	 * @return array<int, true> set of collab group ids
	 */
	private function getCollabIds(array $groupIds): array
	{
		$result = [];

		$res = WorkgroupTable::getList([
			'select' => ['ID'],
			'filter' => [
				'@ID' => array_values(array_unique($groupIds)),
				'=TYPE' => Type::Collab->value,
			],
		]);

		while ($group = $res->fetch())
		{
			$groupId = (int)($group['ID'] ?? 0);
			if ($groupId > 0)
			{
				$result[$groupId] = true;
			}
		}

		return $result;
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}
}
