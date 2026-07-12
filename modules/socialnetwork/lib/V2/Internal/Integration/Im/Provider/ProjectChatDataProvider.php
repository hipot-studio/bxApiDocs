<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Collection;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ChatColorResolver;

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

	public function getProjectIdByChatId(int $chatId): ?int
	{
		if ($chatId <= 0 || !$this->isAvailable())
		{
			return null;
		}

		$chat = ChatTable::getRow([
			'select' => ['ENTITY_ID'],
			'filter' => [
				'=ID' => $chatId,
				'=ENTITY_TYPE' => self::CHAT_ENTITY_TYPE,
			],
		]);

		$projectId = (int)($chat['ENTITY_ID'] ?? 0);

		return $projectId > 0 ? $projectId : null;
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}
}
