<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\HiddenRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\NewAppsCountCache;

final class UnhideCatalogItemCommandHandler
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly HiddenRepository $hiddenRepository = new HiddenRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly NewAppsCountCache $newAppsCountCache = new NewAppsCountCache(),
	)
	{
	}

	public function __invoke(UnhideCatalogItemCommand $command): void
	{
		if ($this->itemRepository->getById($command->catalogItemId) === null)
		{
			throw new CatalogItemNotFoundException($command->catalogItemId);
		}

		if (!$this->itemRepository->isAccessibleToUser(
			$command->catalogItemId,
			$command->userId,
			$this->accessCodes->getUserCodesForItems($command->userId, [$command->catalogItemId]),
		))
		{
			throw new AccessDeniedException('User has no access to this catalog item');
		}

		$this->hiddenRepository->delete($command->userId, $command->catalogItemId);

		$this->newAppsCountCache->forget($command->userId);
	}
}
