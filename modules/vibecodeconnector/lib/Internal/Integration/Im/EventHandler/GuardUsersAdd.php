<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im\EventHandler;

use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersAddEvent;
use Bitrix\Main\EventResult;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatType;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogUserRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatLeavePolicy;

final class GuardUsersAdd
{
	public static function execute(
		BeforeUsersAddEvent $event,
		?CatalogItemRepository $itemRepository = null,
		?CatalogUserRepository $catalogUserRepository = null,
	): ?EventResult
	{
		$chat = $event->getChat();
		if ($chat->getEntityType() !== AppChatType::ENTITY_TYPE)
		{
			return null;
		}

		$userIds = array_values(array_unique(array_map('intval', $event->getUserIds())));
		if ($userIds === [])
		{
			return null;
		}

		$itemRepository ??= new CatalogItemRepository();
		$itemId = (int)$chat->getEntityId();
		$item = $itemRepository->getById($itemId);
		if ($item === null || (int)$item->getAppChatId() !== (int)$chat->getChatId())
		{
			return null;
		}

		$leavePolicy = new AppChatLeavePolicy($catalogUserRepository ?? new CatalogUserRepository());
		$blocked = array_fill_keys($leavePolicy->filterHeldOut($item, $userIds), true);
		if ($blocked === [])
		{
			return null;
		}

		$allowed = array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => !isset($blocked[$userId]),
		));

		return $allowed === []
			? new EventResult(EventResult::ERROR)
			: new EventResult(EventResult::SUCCESS, ['userIds' => $allowed]);
	}
}
