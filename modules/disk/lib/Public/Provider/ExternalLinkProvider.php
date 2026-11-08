<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Provider;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Repository\Interface\ExternalLinkRepositoryInterface;

class ExternalLinkProvider
{
	protected array $cache = [];

	/**
	 * @param ExternalLinkRepositoryInterface $externalLinkRepository
	 */
	public function __construct(
		protected readonly ExternalLinkRepositoryInterface $externalLinkRepository,
	)
	{
	}

	/**
	 * @param mixed $objectId
	 * @param bool $shouldCache
	 * @return ExternalLink|null
	 */
	public function getForUnifiedLinkAccessCheck(mixed $objectId, bool $shouldCache = true): ?ExternalLink
	{
		if ($shouldCache && array_key_exists($objectId, $this->cache['forUnifiedLinkAccessCheck'] ?? []))
		{
			return $this->cache['forUnifiedLinkAccessCheck'][$objectId];
		}

		$link = $this->externalLinkRepository->getForUnifiedLinkAccessCheck($objectId);

		if ($shouldCache)
		{
			$this->cache['forUnifiedLinkAccessCheck'][$objectId] = $link;
		}

		return $link;
	}

	/**
	 * @param array<int, int> $objectIds
	 * @param bool $shouldCache
	 * @return array<int, ExternalLink>
	 */
	public function getForUnifiedLinkAccessCheckCollection(array $objectIds, bool $shouldCache = true): array
	{
		$result = [];
		$objectIdsToLoad = [];

		foreach ($objectIds as $objectId)
		{
			$objectId = (int)$objectId;

			if ($objectId <= 0)
			{
				continue;
			}

			if ($shouldCache && array_key_exists($objectId, $this->cache['forUnifiedLinkAccessCheck'] ?? []))
			{
				$link = $this->cache['forUnifiedLinkAccessCheck'][$objectId];

				if ($link instanceof ExternalLink)
				{
					$result[$objectId] = $link;
				}

				continue;
			}

			$objectIdsToLoad[] = $objectId;
		}

		$objectIdsToLoad = array_values(array_unique($objectIdsToLoad));

		if (empty($objectIdsToLoad))
		{
			return $result;
		}

		$loadedLinks = $this->externalLinkRepository->getForUnifiedLinkAccessCheckCollection($objectIdsToLoad);

		foreach ($objectIdsToLoad as $objectId)
		{
			$link = $loadedLinks[$objectId] ?? null;

			if ($link instanceof ExternalLink)
			{
				$result[$objectId] = $link;
			}

			if ($shouldCache)
			{
				$this->cache['forUnifiedLinkAccessCheck'][$objectId] = $link;
			}
		}

		return $result;
	}

	/**
	 * @param mixed $objectId
	 * @return ExternalLink|null
	 */
	public function getForComponent(mixed $objectId): ?ExternalLink
	{
		return $this->externalLinkRepository->getForComponent($objectId);
	}

	/**
	 * @param string $hash
	 * @return ExternalLink|null
	 */
	public function getForComponentByHash(string $hash): ?ExternalLink
	{
		return $this->externalLinkRepository->getForComponentByHash($hash);
	}

	/**
	 * @param mixed $objectId
	 * @return ExternalLink|null
	 */
	public function getForUse(mixed $objectId): ?ExternalLink
	{
		return $this->externalLinkRepository->getForUse($objectId);
	}
}
