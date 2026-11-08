<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Vibecodeconnector\Internal\Integration\HumanResources\DepartmentCodesCache;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AudienceCodeSet;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

final class AccessWriter
{
	public function __construct(
		private readonly AccessRepository $accessRepository = new AccessRepository(),
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly DepartmentCodesCache $departmentCodesCache = new DepartmentCodesCache(),
	) {}

	/**
	 * @throws ArgumentException
	 */
	public function grant(int $catalogItemId, string $accessCode): bool
	{
		if (!AudienceCodeSet::isSupported($accessCode))
		{
			throw new ArgumentException('accessCode must be one of U<n>, D<n>, DR<n> or AU', 'accessCode');
		}

		return $this->write(
			$catalogItemId,
			function () use ($catalogItemId, $accessCode): AccessWriteResult {
				if ($this->accessRepository->exists($catalogItemId, $accessCode))
				{
					return AccessWriteResult::unchanged();
				}

				$this->accessRepository->grant($catalogItemId, $accessCode);

				return AccessWriteResult::changed(false);
			},
		)->changed;
	}

	public function revoke(int $catalogItemId, string $accessCode): bool
	{
		return $this->write(
			$catalogItemId,
			function () use ($catalogItemId, $accessCode): AccessWriteResult {
				if (!$this->accessRepository->exists($catalogItemId, $accessCode))
				{
					return AccessWriteResult::unchanged();
				}

				$this->accessRepository->revoke($catalogItemId, $accessCode);

				return AccessWriteResult::changed(true);
			},
		)->changed;
	}

	/**
	 * @param string[] $accessCodes
	 * @throws ArgumentException
	 */
	public function setAccess(int $catalogItemId, array $accessCodes): AccessWriteResult
	{
		foreach ($accessCodes as $accessCode)
		{
			if (!is_string($accessCode) || !AudienceCodeSet::isSupported($accessCode))
			{
				throw new ArgumentException('accessCodes must contain only U<n>, D<n>, DR<n> or AU', 'accessCodes');
			}
		}

		return $this->write(
			$catalogItemId,
			function () use ($catalogItemId, $accessCodes): AccessWriteResult {
				$currentCodes = $this->accessRepository->getCodesForItem($catalogItemId);
				$accessCodes = array_values(array_unique($accessCodes));
				sort($currentCodes);
				sort($accessCodes);
				if ($currentCodes === $accessCodes)
				{
					return AccessWriteResult::unchanged();
				}

				$this->accessRepository->setAccess($catalogItemId, $accessCodes);

				return AccessWriteResult::changed(array_diff($currentCodes, $accessCodes) !== []);
			},
		);
	}

	private function write(
		int $catalogItemId,
		\Closure $write,
	): AccessWriteResult
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->itemRepository->lockById($catalogItemId);
			$result = $write();
			if ($result->changed)
			{
				$this->itemRepository->bumpAppChatSyncRevision($catalogItemId);
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}

		if ($result->changed)
		{
			$this->departmentCodesCache->forget();
		}

		return $result;
	}
}
