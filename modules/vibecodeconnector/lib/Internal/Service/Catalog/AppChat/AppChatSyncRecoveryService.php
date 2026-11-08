<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Main\Config\Option;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

final class AppChatSyncRecoveryService
{
	private const MODULE_ID = 'vibecodeconnector';
	private const PENDING_CURSOR_OPTION = 'appChat.syncPendingFrom';

	private const PENDING_ITEMS_PER_RUN = 1;

	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AppChatMembershipService $membershipService = new AppChatMembershipService(),
		private readonly int $pendingItemsPerRun = self::PENDING_ITEMS_PER_RUN,
		private readonly AppChatAvailability $availability = new AppChatAvailability(),
	) {}

	public function run(): void
	{
		$this->requeuePendingItems();
	}

	public function requeuePendingItems(): int
	{
		if (!$this->availability->isEnabled())
		{
			return 0;
		}

		$cursorBefore = $this->readCursor(self::PENDING_CURSOR_OPTION);
		$itemIds = $this->itemRepository->getIdsNeedingAppChatSync($cursorBefore, $this->pendingItemsPerRun);
		if ($itemIds === [] && $cursorBefore > 0)
		{
			$this->writeCursor(self::PENDING_CURSOR_OPTION, 0);

			return 0;
		}

		$queued = 0;
		foreach ($itemIds as $itemId)
		{
			try
			{
				$this->membershipService->scheduleItemSync($itemId);
				$queued++;
			}
			catch (\Throwable $exception)
			{
				\AddMessage2Log(
					"Catalog item {$itemId}: the pending app-chat sync was not re-queued, "
						. $exception->getMessage(),
					self::MODULE_ID,
				);

				break;
			}
		}

		$this->writeCursor(
			self::PENDING_CURSOR_OPTION,
			count($itemIds) < $this->pendingItemsPerRun ? 0 : max($itemIds),
		);

		return $queued;
	}

	private function readCursor(string $name): int
	{
		return max(0, (int)Option::get(self::MODULE_ID, $name, '0'));
	}

	private function writeCursor(string $name, int $cursor): void
	{
		if ($cursor <= 0)
		{
			Option::delete(self::MODULE_ID, ['name' => $name]);

			return;
		}

		Option::set(self::MODULE_ID, $name, (string)$cursor);
	}
}
