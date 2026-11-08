<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\NotOwnerException;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\CatalogItemEmbeddingAccessSynchronizer;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\HiddenRepository;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\ViewedRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\HiddenAccessCleaner;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon\IconFileResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\NewAppsCountInvalidator;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Writer\EntryWriter;

final class UpdateCatalogItemCommandHandler
{
	public function __construct(
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
		private readonly EntryWriter $writer = new EntryWriter(),
		private readonly AccessRepository $accessRepository = new AccessRepository(),
		private readonly IconFileResolver $iconFileResolver = new IconFileResolver(),
		private readonly IconStorageService $iconStorage = new IconStorageService(),
		private readonly HiddenRepository $hiddenRepository = new HiddenRepository(),
		private readonly ViewedRepository $viewedRepository = new ViewedRepository(),
		private readonly HiddenAccessCleaner $hiddenAccessCleaner = new HiddenAccessCleaner(),
		private readonly CatalogItemEmbeddingAccessSynchronizer $embeddingAccessSynchronizer =
			new CatalogItemEmbeddingAccessSynchronizer(),
		private readonly NewAppsCountInvalidator $newAppsCountInvalidator = new NewAppsCountInvalidator(),
	)
	{
	}

	public function __invoke(UpdateCatalogItemCommand $command): UpdateCatalogItemCommandResult
	{
		$resolvedIconFileId = null;
		if (array_key_exists('iconUrl', $command->fields))
		{
			$preloaded = $this->repository->getById($command->catalogItemId);
			if ($preloaded === null)
			{
				throw new CatalogItemNotFoundException($command->catalogItemId);
			}

			if ($preloaded->getOwnerId() !== $command->userId)
			{
				throw new NotOwnerException();
			}

			$rawIconUrl = is_string($command->fields['iconUrl']) ? $command->fields['iconUrl'] : null;
			$resolvedIconFileId = $this->iconFileResolver->resolve($rawIconUrl, $preloaded->getPairingIss());
		}

		$accessTypeChanged = false;
		$result = $this->updateInTransaction($command, $resolvedIconFileId, $accessTypeChanged);

		if ($accessTypeChanged)
		{
			$this->newAppsCountInvalidator->forgetAllForAccessTypeChangeOf($result->item);
			$this->embeddingAccessSynchronizer->syncForItem($command->catalogItemId);
		}

		if (array_key_exists('iconUrl', $command->fields) && $result->displacedIconFileId !== null)
		{
			$this->iconStorage->delete($result->displacedIconFileId);

			return new UpdateCatalogItemCommandResult($result->item);
		}

		return $result;
	}

	private function updateInTransaction(
		UpdateCatalogItemCommand $command,
		?int $resolvedIconFileId,
		bool &$accessTypeChanged,
	): UpdateCatalogItemCommandResult
	{
		$iconPersisted = false;
		$displacedIconFileId = null;

		try
		{
			if (!CatalogItemRepository::supportsRowLocking())
			{
				return $this->updateLockedItem(
					$command,
					$resolvedIconFileId,
					$iconPersisted,
					$displacedIconFileId,
					$accessTypeChanged,
				);
			}

			$connection = Application::getConnection();
			$connection->startTransaction();

			try
			{
				$result = $this->updateLockedItem(
					$command,
					$resolvedIconFileId,
					$iconPersisted,
					$displacedIconFileId,
					$accessTypeChanged,
				);
				$connection->commitTransaction();
			}
			catch (\Throwable $e)
			{
				try
				{
					$connection->rollbackTransaction();
				}
				catch (TransactionException)
				{
				}

				$iconPersisted = false;

				throw $e;
			}

			return $result;
		}
		catch (\Throwable $e)
		{
			$orphaned = $iconPersisted ? $displacedIconFileId : $resolvedIconFileId;
			if ($orphaned !== null)
			{
				$this->iconStorage->delete($orphaned);
			}

			throw $e;
		}
	}

	private function updateLockedItem(
		UpdateCatalogItemCommand $command,
		?int $resolvedIconFileId,
		bool &$iconPersisted,
		?int &$displacedIconFileId,
		bool &$accessTypeChanged,
	): UpdateCatalogItemCommandResult
	{
		$this->repository->lockById($command->catalogItemId);

		$item = $this->repository->getById($command->catalogItemId);
		if ($item === null)
		{
			throw new CatalogItemNotFoundException($command->catalogItemId);
		}

		if ($item->getOwnerId() !== $command->userId)
		{
			throw new NotOwnerException();
		}

		$previousAccessType = $item->getAccessType();
		$fields = $command->fields;

		if (array_key_exists('title', $fields))
		{
			$item->setTitle(trim((string)$fields['title']));
		}

		if (array_key_exists('description', $fields))
		{
			$item->setDescription($this->normalizeNullableString($fields['description']));
		}

		if (array_key_exists('editUrl', $fields))
		{
			$item->setEditUrl($this->normalizeNullableString($fields['editUrl']));
		}

		if (array_key_exists('viewUrl', $fields))
		{
			$item->setViewUrl($this->normalizeNullableString($fields['viewUrl']));
			$this->assertViewUrlIsPresentForApplication($item->getType(), $item->getViewUrl());
		}

		if (array_key_exists('chatId', $fields))
		{
			$item->setChatId($fields['chatId'] !== null ? (int)$fields['chatId'] : null);
		}

		if (array_key_exists('externalId', $fields))
		{
			$item->setExternalId($this->normalizeNullableString($fields['externalId']));
		}

		if (array_key_exists('accessType', $fields))
		{
			$accessType = CatalogItemAccessType::tryFrom((string)$fields['accessType']);
			if ($accessType === null)
			{
				throw new ArgumentException('Unsupported accessType value', 'accessType');
			}

			$item->setAccessType($accessType);
		}
		if (array_key_exists('iconFileId', $fields))
		{
			$newIconFileId = $fields['iconFileId'] !== null ? (int)$fields['iconFileId'] : null;
			$displacedIconFileId = $this->writer->saveReplacingIconDeferred($item, $newIconFileId);
		}
		elseif (array_key_exists('iconUrl', $fields))
		{
			$displacedIconFileId = $this->writer->saveReplacingIconDeferred($item, $resolvedIconFileId);
			$iconPersisted = true;
		}
		else
		{
			$this->writer->save($item);
		}

		if ($item->getAccessType() !== CatalogItemAccessType::ACL)
		{
			$this->accessRepository->deleteAllForCatalogItem($command->catalogItemId);

			if ($item->getAccessType() === CatalogItemAccessType::Private)
			{
				$this->hiddenRepository->deleteAllForCatalogItem($command->catalogItemId);
				$this->viewedRepository->deleteAllForCatalogItem($command->catalogItemId);
			}
		}
		elseif ($previousAccessType !== CatalogItemAccessType::ACL)
		{
			// Narrowing public to acl takes the item away from everyone outside the new
			// codes, so their marks go the same way as on a private switch above.
			$this->hiddenAccessCleaner->cleanupLostAccess($command->catalogItemId);
		}

		$accessTypeChanged = $item->getAccessType() !== $previousAccessType;

		return new UpdateCatalogItemCommandResult($item, $displacedIconFileId);
	}

	private function assertViewUrlIsPresentForApplication(CatalogItemType $type, ?string $viewUrl): void
	{
		if ($type === CatalogItemType::Application && $viewUrl === null)
		{
			throw new ArgumentException('viewUrl is required for application items', 'viewUrl');
		}
	}

	private function normalizeNullableString(mixed $value): ?string
	{
		if (!is_string($value))
		{
			return null;
		}

		$value = trim($value);

		return $value === '' ? null : $value;
	}
}
