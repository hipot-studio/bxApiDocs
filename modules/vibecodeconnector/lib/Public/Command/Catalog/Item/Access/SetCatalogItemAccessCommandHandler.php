<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Access;

use Bitrix\Main\ArgumentException;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\NotOwnerException;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\HiddenAccessCleaner;

final class SetCatalogItemAccessCommandHandler
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessRepository $accessRepository = new AccessRepository(),
		private readonly HiddenAccessCleaner $hiddenAccessCleaner = new HiddenAccessCleaner(),
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

		$codes = array_values(array_unique(array_filter(
			$command->accessCodes,
			static fn (mixed $code): bool => is_string($code) && trim($code) !== '',
		)));

		$previousCodes = $this->accessRepository->getCodesForItem($command->catalogItemId);
		$this->accessRepository->setAccess($command->catalogItemId, $codes);

		if (array_diff($previousCodes, $codes) !== [])
		{
			$this->hiddenAccessCleaner->cleanupLostAccess($command->catalogItemId);
		}
	}
}
