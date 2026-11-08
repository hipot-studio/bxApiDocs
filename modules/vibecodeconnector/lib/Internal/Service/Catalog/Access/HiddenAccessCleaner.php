<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Exception\LiveDepartmentAccessUnavailableException;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\HiddenRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\ViewedRepository;

final class HiddenAccessCleaner
{
	public function __construct(
		private readonly HiddenRepository $hiddenRepository = new HiddenRepository(),
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly ViewedRepository $viewedRepository = new ViewedRepository(),
		private readonly AccessRepository $accessRepository = new AccessRepository(),
	)
	{
	}

	/**
	 * The view mark goes away together with the hidden flag: otherwise an app granted
	 * again would never be new for that user, because the novelty criterion would match
	 * while the viewed predicate kept cutting it off.
	 */
	public function cleanupLostAccess(int $catalogItemId): void
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null)
		{
			$this->hiddenRepository->deleteAllForCatalogItem($catalogItemId);

			return;
		}

		$userIds = $this->hiddenRepository->userIdsForItem($catalogItemId);
		$codes = $item->getAccessType() === CatalogItemAccessType::ACL
			? $this->accessRepository->getCodesForItem($catalogItemId)
			: [];
		if ($item->isDeactivated())
		{
			$this->hiddenRepository->deleteForUsers($catalogItemId, $userIds);
		}
		elseif ($item->getAccessType() !== CatalogItemAccessType::Public)
		{
			$candidates = array_values(array_filter(
				$userIds,
				static fn (int $userId): bool => $userId !== $item->getOwnerId(),
			));
			try
			{
				$liveMembers = $item->getAccessType() === CatalogItemAccessType::ACL && $candidates !== []
					? array_fill_keys($this->accessCodes->filterLiveDepartmentMembers($candidates, $codes), true)
					: [];
			}
			catch (LiveDepartmentAccessUnavailableException)
			{
				return;
			}
			$lostUserIds = [];
			foreach ($candidates as $userId)
			{
				if (isset($liveMembers[$userId]))
				{
					continue;
				}
				if ($item->getAccessType() === CatalogItemAccessType::ACL
					&& array_intersect($codes, $this->accessCodes->getStoredUserCodes($userId)) !== [])
				{
					continue;
				}
				$lostUserIds[] = $userId;
			}
			$this->hiddenRepository->deleteForUsers($catalogItemId, $lostUserIds);
		}

		$this->cleanupViewMarks($item, $codes);
	}

	/**
	 * Unlike the hidden flag, the view mark is written for every user the catalog was shown
	 * to, so the marks are cleaned in one statement instead of a per-user access check.
	 */
	private function cleanupViewMarks(CatalogItem $item, array $codes): void
	{
		if ($item->getAccessType() === CatalogItemAccessType::Public)
		{
			return;
		}

		$this->viewedRepository->deleteForUsersWithoutAccess(
			(int)$item->getId(),
			$item->getOwnerId(),
			$codes,
		);
	}
}
