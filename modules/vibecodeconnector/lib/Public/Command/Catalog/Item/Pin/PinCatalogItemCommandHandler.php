<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Pin;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\PinRepository;

final class PinCatalogItemCommandHandler
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly PinRepository $pinRepository = new PinRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
	)
	{
	}

	public function __invoke(PinCatalogItemCommand $command): void
	{
		if ($this->itemRepository->getById($command->catalogItemId) === null)
		{
			throw new CatalogItemNotFoundException($command->catalogItemId);
		}

		if (!$this->itemRepository->isAccessibleToUser(
			$command->catalogItemId,
			$command->userId,
			$this->accessCodes->getUserCodes($command->userId),
		))
		{
			throw new AccessDeniedException('User has no access to this catalog item');
		}

		$this->pinRepository->pin($command->userId, $command->catalogItemId);
	}
}
