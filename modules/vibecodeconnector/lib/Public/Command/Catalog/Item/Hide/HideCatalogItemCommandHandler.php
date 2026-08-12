<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Application;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\HiddenRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\PinRepository;

final class HideCatalogItemCommandHandler
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly HiddenRepository $hiddenRepository = new HiddenRepository(),
		private readonly PinRepository $pinRepository = new PinRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
	)
	{
	}

	public function __invoke(HideCatalogItemCommand $command): void
	{
		$item = $this->itemRepository->getById($command->catalogItemId);
		if ($item === null)
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

		if ($item->getOwnerId() === $command->userId)
		{
			throw new AccessDeniedException('User cannot hide own catalog item');
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$this->hiddenRepository->upsert($command->userId, $command->catalogItemId);
			$this->pinRepository->unpin($command->userId, $command->catalogItemId);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}
	}
}
