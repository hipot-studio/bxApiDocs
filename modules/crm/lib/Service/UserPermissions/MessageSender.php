<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\ItemIdentifier;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->messageSender()
 */
final class MessageSender
{
	public function __construct(
		private readonly EntityPermissions\Type $entityType,
		private readonly EntityPermissions\Item $item,
	)
	{
	}

	public function canSend(int $entityTypeId, int $entityId): bool
	{
		return $this->item->canUpdate($entityTypeId, $entityId);
	}

	public function canSendItemIdentifier(ItemIdentifier $itemIdentifier): bool
	{
		return $this->item->canUpdateItemIdentifier($itemIdentifier);
	}

	public function canSendFromSomeItemsInCrmOrAutomatedSolutions(): bool
	{
		return $this->entityType->canUpdateSomeItemsInCrmOrAutomatedSolutions();
	}

	public function canSendFromItems(int $entityTypeId): bool
	{
		return $this->entityType->canUpdateItems($entityTypeId);
	}

	public function canSendFromItemsInCategory(int $entityTypeId, int $categoryId): bool
	{
		return $this->entityType->canUpdateItemsInCategory($entityTypeId, $categoryId);
	}

	public function canConfigureChannels(): bool
	{
		return $this->canSendFromSomeItemsInCrmOrAutomatedSolutions();
	}
}
