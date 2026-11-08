<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im\EventHandler;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUsersDeleteEvent;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatType;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogUserRepository;

final class LeaveHandler
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly CatalogUserRepository $catalogUserRepository = new CatalogUserRepository(),
	) {}

	public static function execute(AfterUsersDeleteEvent $event): void
	{
		(new self())->handle($event);
	}

	public function handle(AfterUsersDeleteEvent $event): void
	{
		$chat = $event->getChat();
		$leftUserId = $this->findUserWhoLeftOnTheirOwn($event);

		if ($chat->getEntityType() !== AppChatType::ENTITY_TYPE || $leftUserId === null)
		{
			return;
		}

		$itemId = (int)$chat->getEntityId();
		$item = $this->itemRepository->getById($itemId);
		if ($item === null || (int)$item->getAppChatId() !== (int)$chat->getChatId())
		{
			return;
		}

		$this->catalogUserRepository->markLeft($itemId, $leftUserId);
	}

	private function findUserWhoLeftOnTheirOwn(AfterUsersDeleteEvent $event): ?int
	{
		$removedUserIds = array_values(array_unique(array_map('intval', $event->getUserIds())));
		if (count($removedUserIds) !== 1 || $event->getInitiatorId() !== $removedUserIds[0])
		{
			return null;
		}

		return $removedUserIds[0];
	}
}
