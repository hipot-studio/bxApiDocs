<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access;

use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\HiddenRepository;

final class HiddenAccessCleaner
{
	public function __construct(
		private readonly HiddenRepository $hiddenRepository = new HiddenRepository(),
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
	)
	{
	}

	public function cleanupLostAccess(int $catalogItemId): void
	{
		foreach ($this->hiddenRepository->userIdsForItem($catalogItemId) as $userId)
		{
			if (!$this->itemRepository->isAccessibleToUser(
				$catalogItemId,
				$userId,
				$this->accessCodes->getUserCodes($userId),
			))
			{
				$this->hiddenRepository->delete($userId, $catalogItemId);
			}
		}
	}
}
