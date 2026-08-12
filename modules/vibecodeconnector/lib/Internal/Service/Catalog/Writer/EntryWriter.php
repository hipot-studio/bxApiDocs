<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Writer;

use Bitrix\Main\Application;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemColors;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\HiddenRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\LastOpenedRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\PinRepository;

final class EntryWriter
{
	public function __construct(
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
		private readonly PinRepository $pinRepository = new PinRepository(),
		private readonly HiddenRepository $hiddenRepository = new HiddenRepository(),
		private readonly AccessRepository $accessRepository = new AccessRepository(),
		private readonly LastOpenedRepository $lastOpenedRepository = new LastOpenedRepository(),
		private readonly IconStorageService $iconStorage = new IconStorageService(),
		private readonly CatalogItemColors $colors = new CatalogItemColors(),
	) {}

	/**
	 * Creates and persists a new catalog item, guaranteeing no orphaned icon file on failure.
	 * The icon file is downloaded by the caller before the entity exists; from this point the
	 * writer owns its lifecycle. If the entity save fails, the freshly downloaded file (if set)
	 * is removed and the exception is rethrown, symmetrically to saveReplacingIcon().
	 */
	public function create(
		string $title,
		CatalogItemType $type,
		CatalogItemAccessType $accessType,
		int $ownerId,
		?string $description = null,
		?string $editUrl = null,
		?string $viewUrl = null,
		?int $chatId = null,
		?string $externalId = null,
		?string $pairingIss = null,
		?int $iconFileId = null,
	): CatalogItem {
		$item = new CatalogItem(
			title: $title,
			type: $type,
			accessType: $accessType,
			ownerId: $ownerId,
			color: $this->colors->getRandom(),
			iconFileId: $iconFileId,
			chatId: $chatId,
			externalId: $externalId,
			description: $description,
			editUrl: $editUrl,
			viewUrl: $viewUrl,
			pairingIss: $pairingIss,
		);
		try
		{
			$this->repository->save($item);
		}
		catch (\Throwable $e)
		{
			if ($iconFileId !== null)
			{
				$this->iconStorage->delete($iconFileId);
			}

			throw $e;
		}

		return $item;
	}

	public function save(CatalogItem $item): void
	{
		$this->repository->save($item);
	}

	/**
	 * Persists the item with a replaced icon file, guaranteeing no orphaned files in
	 * either direction. On success the previous file is deleted only after the entity is
	 * stored. On a failed save the freshly downloaded new file (if set and different from
	 * the previous one) is removed, the entity's icon id is restored to its previous value,
	 * and the exception is rethrown.
	 */
	public function saveReplacingIcon(CatalogItem $item, ?int $newIconFileId): void
	{
		$oldIconFileId = $item->getIconFileId();
		$item->setIconFileId($newIconFileId);
		try
		{
			$this->repository->save($item);
		}
		catch (\Throwable $e)
		{
			$item->setIconFileId($oldIconFileId);
			if ($newIconFileId !== null && $newIconFileId !== $oldIconFileId)
			{
				$this->iconStorage->delete($newIconFileId);
			}

			throw $e;
		}

		if ($oldIconFileId !== null && $oldIconFileId !== $newIconFileId)
		{
			$this->iconStorage->delete($oldIconFileId);
		}
	}

	public function delete(CatalogItem $item): void
	{
		$itemId = $item->getId();
		if ($itemId === null)
		{
			return;
		}

		$iconFileId = $item->getIconFileId();

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->pinRepository->deleteAllForCatalogItem($itemId);
			$this->hiddenRepository->deleteAllForCatalogItem($itemId);
			$this->accessRepository->deleteAllForCatalogItem($itemId);
			$this->lastOpenedRepository->deleteAllForCatalogItem($itemId);
			$this->repository->delete($itemId);
			if ($iconFileId !== null)
			{
				$this->iconStorage->delete($iconFileId);
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}
	}
}
