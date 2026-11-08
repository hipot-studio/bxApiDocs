<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Access;

use Bitrix\Main\ArgumentException;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\NotOwnerException;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\CatalogItemEmbeddingAccessSynchronizer;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\AccessChangeHandler;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\AccessWriter;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\HiddenAccessCleaner;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\NewAppsCountInvalidator;

final class SetCatalogItemAccessCommandHandler
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessWriter $accessWriter = new AccessWriter(),
		private readonly HiddenAccessCleaner $hiddenAccessCleaner = new HiddenAccessCleaner(),
		private readonly AccessChangeHandler $accessChangeHandler = new AccessChangeHandler(),
		private readonly CatalogItemEmbeddingAccessSynchronizer $embeddingAccessSynchronizer =
			new CatalogItemEmbeddingAccessSynchronizer(),
		private readonly NewAppsCountInvalidator $newAppsCountInvalidator = new NewAppsCountInvalidator(),
	)
	{
	}

	public function __invoke(SetCatalogItemAccessCommand $command): void
	{
		$item = $this->itemRepository->getById($command->catalogItemId);
		if ($item === null)
		{
			throw new CatalogItemNotFoundException($command->catalogItemId);
		}

		if ($item->getOwnerId() !== $command->userId)
		{
			throw new NotOwnerException();
		}

		if ($item->getAccessType() !== CatalogItemAccessType::ACL)
		{
			throw new ArgumentException('accessCodes can be set only for acl items', 'accessCodes');
		}

		$result = $this->accessWriter->setAccess($command->catalogItemId, $command->accessCodes);
		if (!$result->changed)
		{
			return;
		}

		if ($result->revoked)
		{
			$this->hiddenAccessCleaner->cleanupLostAccess($command->catalogItemId);
		}
		$this->newAppsCountInvalidator->forgetAllFor($item);
		$this->accessChangeHandler->handle($item);
		$this->embeddingAccessSynchronizer->syncForItem($command->catalogItemId);
	}
}
