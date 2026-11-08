<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Main\Config\Option;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

final class AppChatTeardownService
{
	private const ITEMS_PER_READ = 100;
	private const TEARDOWN_BUDGET_SECONDS = 20.0;
	private const MODULE_ID = 'vibecodeconnector';
	private const TEARDOWN_CURSOR_OPTION = 'appChat.teardownFrom';

	public function __construct(
		private readonly AppChatService $appChatService = new AppChatService(),
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly float $teardownBudgetSeconds = self::TEARDOWN_BUDGET_SECONDS,
		private readonly int $itemsPerRead = self::ITEMS_PER_READ,
	) {}

	public function applyPendingTeardowns(): void
	{
		$deadline = microtime(true) + $this->teardownBudgetSeconds;
		$cursorBefore = $this->readCursor();
		if (microtime(true) >= $deadline)
		{
			return;
		}

		$cursor = $cursorBefore;
		$items = $this->itemRepository->getItemsNeedingAppChatTeardown($cursor, $this->itemsPerRead);
		$found = false;
		foreach ($items as $item)
		{
			$found = true;
			if (microtime(true) >= $deadline)
			{
				break;
			}

			$cursor = (int)$item->getId();
			if ($item->getDeactivatedAt() === null || (int)$item->getAppChatId() <= 0)
			{
				continue;
			}
			try
			{
				$this->teardown($item);
			}
			catch (\Throwable $exception)
			{
				\AddMessage2Log(
					"Catalog item {$cursor}: app-chat teardown was not completed, {$exception->getMessage()}",
					self::MODULE_ID,
				);
			}
		}

		$this->moveCursor($found ? $cursor : 0, $cursorBefore);
	}

	public function drainPendingTeardowns(): void
	{
		do
		{
			$items = $this->itemRepository->getItemsWithAppChat(0, $this->itemsPerRead);
			$processed = 0;
			foreach ($items as $item)
			{
				$this->teardown($item);
				if ((int)$item->getAppChatId() > 0)
				{
					throw new \RuntimeException(
						'Catalog item ' . (int)$item->getId() . ': app-chat teardown did not clear the binding',
					);
				}
				$processed++;
			}

			if ($processed === 0)
			{
				return;
			}
		}
		while ($processed === $this->itemsPerRead);
	}

	public function teardown(CatalogItem $item): void
	{
		if ((int)$item->getId() <= 0 || (int)$item->getAppChatId() <= 0)
		{
			return;
		}

		$this->appChatService->deleteAppChat($item);
	}

	private function readCursor(): int
	{
		return (int)Option::get(self::MODULE_ID, self::TEARDOWN_CURSOR_OPTION, '0');
	}

	private function moveCursor(int $cursor, int $cursorBefore): void
	{
		if ($cursor === $cursorBefore)
		{
			return;
		}
		if ($cursor <= 0)
		{
			Option::delete(self::MODULE_ID, ['name' => self::TEARDOWN_CURSOR_OPTION]);

			return;
		}

		Option::set(self::MODULE_ID, self::TEARDOWN_CURSOR_OPTION, (string)$cursor);
	}
}
