<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogUserRepository;

final class AppChatOnDemandService
{
	public function __construct(
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
		private readonly AppChatService $appChatService = new AppChatService(),
		private readonly AppChatMembershipService $membershipService = new AppChatMembershipService(),
		private readonly AppChatAvailability $availability = new AppChatAvailability(),
		private readonly CatalogUserRepository $catalogUserRepository = new CatalogUserRepository(),
	) {}

	public function ensureUserChatBestEffort(int $catalogItemId, int $userId): int
	{
		if ($catalogItemId <= 0 || $userId <= 0 || !$this->availability->isEnabled())
		{
			return 0;
		}

		try
		{
			$item = $this->repository->getById($catalogItemId);
			if (
				$item === null
				|| $item->isDeactivated()
				|| $item->getType() !== CatalogItemType::Application
			)
			{
				return 0;
			}

			$this->catalogUserRepository->forgetLeft($catalogItemId, $userId);

			if ($this->hasNoLinkedChat($item))
			{
				return 0;
			}

			return $this->membershipService->subscribeUserBestEffort($item, $userId);
		}
		catch (\Throwable)
		{
			return 0;
		}
	}

	/**
	 * @return int chat id, or 0 when the chat could not be created
	 */
	public function createChatForOwner(int $catalogItemId, int $userId): int
	{
		if ($catalogItemId <= 0 || $userId <= 0 || !$this->availability->isEnabled())
		{
			return 0;
		}

		try
		{
			$item = $this->repository->getById($catalogItemId);
			if (
				$item === null
				|| $item->isDeactivated()
				|| $item->getType() !== CatalogItemType::Application
				|| $item->getOwnerId() !== $userId
			)
			{
				return 0;
			}

			$existing = $this->appChatService->getValidLinkedChatId($item);
			if ($existing > 0)
			{
				return $existing;
			}

			$this->appChatService->ensureAppChatOnDemandWithResult($item);

			$current = $this->repository->getById($catalogItemId);

			return $current === null
				? 0
				: $this->membershipService->subscribeUserBestEffort($current, $userId);
		}
		catch (\Throwable)
		{
			return 0;
		}
	}

	public function isChatCreatableByOwner(int $catalogItemId, int $userId): bool
	{
		if ($catalogItemId <= 0 || $userId <= 0 || !$this->availability->isEnabled())
		{
			return false;
		}

		$item = $this->repository->getById($catalogItemId);

		return $item !== null && $this->isChatCreatableForOwner($item, $userId);
	}

	public function isChatCreatableForOwner(CatalogItem $item, int $userId): bool
	{
		if ($userId <= 0 || !$this->availability->isEnabled())
		{
			return false;
		}

		return !$item->isDeactivated()
			&& $item->getType() === CatalogItemType::Application
			&& $item->getOwnerId() === $userId
			&& $this->appChatService->getValidLinkedChatId($item) <= 0
		;
	}

	private function hasNoLinkedChat(CatalogItem $item): bool
	{
		return (int)$item->getAppChatId() <= 0;
	}
}
