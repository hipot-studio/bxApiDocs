<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Placement;

use Bitrix\Main\Application;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Entity\CurrentUser;
use Bitrix\Rest\Internal\Repository\EmbeddingAccessRepository;
use Bitrix\Rest\Internal\Service\Embedding\ApplicationOnlyPlacements;
use Bitrix\Rest\Internal\Service\Embedding\LegacyNonAppEmbeddingsAvailability;
use Bitrix\Rest\PlacementTable;

final class HandlerReader
{
	private const OWNER_AWARE_CACHE_FORMAT = '|owner_rows_v3';
	private const OWNER_AWARE_ACCESS_CACHE_FORMAT = '|owner_access_rows_v1';

	public function __construct(
		private readonly LegacyNonAppEmbeddingsAvailability $availability = new LegacyNonAppEmbeddingsAvailability(),
		private readonly EmbeddingAccessRepository $accessRepository = new EmbeddingAccessRepository(),
		private readonly LegacyHandlerAdapter $adapter = new LegacyHandlerAdapter(),
		private readonly ApplicationOnlyPlacements $applicationOnlyPlacements = new ApplicationOnlyPlacements(),
	)
	{
	}

	public static function resolveUserId(?int $userId): int
	{
		return $userId
			?? (int)(CurrentUser::getInstance()->getId() ?? PlacementTable::DEFAULT_USER_ID_VALUE);
	}

	public function readHandlers(string $placement, bool $skipInstallCheck, ?int $userId): array
	{
		$userId = self::resolveUserId($userId);
		if (!$this->availability->isEnabled())
		{
			return $this->getLegacyHandlers($placement, $skipInstallCheck, $userId);
		}

		$rows = $this->getOwnerAwareRows($placement, $userId);

		return $this->adapter->makeHandlers(
			$rows,
			$placement,
			$skipInstallCheck,
			$this->getAccessibleEmbeddingIds($rows, $userId, $placement),
		);
	}

	public function readAccessibleIds(string $placement, ?int $userId): array
	{
		$userId = self::resolveUserId($userId);
		if (!$this->availability->isEnabled())
		{
			return $this->getLegacyAccessibleIds($placement, $userId);
		}

		$rows = $this->getOwnerAwareAccessRows($placement, $userId);

		return $this->adapter->makeAccessibleIds(
			$rows,
			$placement,
			$userId,
			$this->getAccessibleEmbeddingIds($rows, $userId, $placement),
		);
	}

	private function getPlacementCacheId(string $placement, int $userId): string
	{
		return 'rest_placement_list|' . $placement . '|' . LANGUAGE_ID . '|user_' . $userId;
	}

	private function getAccessibleHandlerCacheId(string $placement, int $userId): string
	{
		return 'rest_placement_access_ids|' . $placement . '|user_' . $userId;
	}

	private function getLegacyHandlers(string $placement, bool $skipInstallCheck, int $userId): array
	{
		$query = new HandlerQuery();
		$mapper = new HandlerCacheRowMapper();
		$handlers = $this->getHandlerCache()->get(
			$this->getPlacementCacheId($placement, $userId),
			static function () use ($query, $mapper, $placement, $userId): array {
				$result = [];
				$collection = $query->getLegacyHandlers($placement, $userId)->fetchCollection();
				$collection->fillLangAll();
				foreach ($collection as $handler)
				{
					$result[] = $mapper->mapLegacyHandler($handler);
				}

				return $result;
			},
		);

		return $this->adapter->makeHandlers($handlers, $placement, $skipInstallCheck, []);
	}

	private function getLegacyAccessibleIds(string $placement, int $userId): array
	{
		$query = new HandlerQuery();
		$mapper = new HandlerCacheRowMapper();
		$candidates = $this->getHandlerCache()->get(
			$this->getAccessibleHandlerCacheId($placement, $userId),
			static function () use ($query, $mapper, $placement, $userId): array {
				$result = [];
				$queryResult = $query->getLegacyHandlers($placement, $userId);
				while ($handler = $queryResult->fetch())
				{
					$result[] = $mapper->mapLegacyAccessCandidate($handler);
				}

				return $result;
			},
		);

		return $this->adapter->makeAccessibleIds($candidates, $placement, $userId, []);
	}

	private function getOwnerAwareRows(string $placement, int $userId): array
	{
		return $this->getHandlerCache()->get(
			$this->getOwnerAwareCacheId($placement, $userId),
			static function () use ($placement, $userId): array {
				$query = new HandlerQuery();
				$mapper = new HandlerCacheRowMapper();

				return $mapper->mapOwnerAwareCollection(
					$query->getOwnerAwareCandidates($placement, $userId),
				);
			},
		);
	}

	private function getOwnerAwareAccessRows(string $placement, int $userId): array
	{
		return $this->getHandlerCache()->get(
			$this->getAccessibleHandlerCacheId($placement, $userId) . self::OWNER_AWARE_ACCESS_CACHE_FORMAT,
			static function () use ($placement, $userId): array {
				$query = new HandlerQuery();
				$mapper = new HandlerCacheRowMapper();
				$result = [];
				$queryResult = $query->getOwnerAwareAccessCandidates($placement, $userId);
				while ($handler = $queryResult->fetch())
				{
					$result[] = $mapper->mapOwnerAwareAccessCandidate($handler);
				}

				return $result;
			},
		);
	}

	private function getAccessibleEmbeddingIds(array $rows, int $userId, string $placement): array
	{
		if ($this->applicationOnlyPlacements->contains($placement))
		{
			return [];
		}

		$embeddingIds = [];
		$legacyAppIdPresenter = new LegacyAppIdPresenter();
		foreach ($rows as $row)
		{
			$embeddingId = $legacyAppIdPresenter->decodeEmbeddingId((int)($row['APP_ID'] ?? 0));
			if ($embeddingId !== null)
			{
				$embeddingIds[] = $embeddingId;
			}
		}

		if ($embeddingIds === [] || $userId <= 0)
		{
			return [];
		}
		if (RestUserModel::createFromId($userId)->isAdmin())
		{
			return $embeddingIds;
		}

		return $this->accessRepository->findAccessibleEmbeddingIds($embeddingIds, $userId);
	}

	private function getHandlerCache(): HandlerCache
	{
		return new HandlerCache(
			Application::getInstance()->getManagedCache(),
			PlacementTable::CACHE_TTL,
			PlacementTable::CACHE_DIR,
		);
	}

	private function getOwnerAwareCacheId(string $placement, int $userId): string
	{
		return $this->getPlacementCacheId($placement, $userId) . self::OWNER_AWARE_CACHE_FORMAT;
	}
}
