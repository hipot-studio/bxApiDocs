<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Im\V2\Chat;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ChatLocator;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ChatWritePermission;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ImGate;

final class AppChatWriteAccessService
{
	public function __construct(
		private readonly ChatLocator $chatLocator = new ChatLocator(),
		private readonly AppChatAvailability $availability = new AppChatAvailability(),
		private readonly ChatWritePermission $writePermission = new ChatWritePermission(),
		private readonly ImGate $imGate = new ImGate(),
	) {}

	public function isWritingAllowed(CatalogItem $item): bool
	{
		$chat = $this->loadChat($item);

		return $chat === null || $this->writePermission->isAllowedForMembers($chat);
	}

	/**
	 * @param CatalogItem[] $items
	 * @return array<int, bool|null>
	 */
	public function resolveWritingAllowed(array $items): array
	{
		$result = [];
		foreach ($items as $item)
		{
			$result[(int)$item->getId()] = null;
		}

		if ($result === [] || !$this->availability->isEnabled() || !$this->imGate->isAvailable())
		{
			return $result;
		}

		$this->chatLocator->prefetch(array_map(
			static fn (CatalogItem $item): int => (int)$item->getAppChatId(),
			$items,
		));

		foreach ($items as $item)
		{
			$chat = $this->loadChat($item);
			if ($chat !== null)
			{
				$result[(int)$item->getId()] = $this->writePermission->isAllowedForMembers($chat);
			}
		}

		return $result;
	}

	/**
	 * @return bool whether the value was applied; false means the chat is not there to carry it
	 */
	public function setWritingAllowed(CatalogItem $item, bool $allowed): bool
	{
		$chat = $this->loadChat($item);
		if ($chat === null)
		{
			return false;
		}

		return $this->writePermission->setAllowedForMembers($chat, $item->getOwnerId(), $allowed);
	}

	private function loadChat(CatalogItem $item): ?Chat
	{
		if (!$this->availability->isEnabled() || !$this->imGate->isAvailable())
		{
			return null;
		}

		$chatId = (int)$item->getAppChatId();

		return $chatId > 0 ? $this->chatLocator->find($chatId) : null;
	}
}
