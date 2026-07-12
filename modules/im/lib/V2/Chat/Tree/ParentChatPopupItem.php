<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\NullChat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Rest\PopupDataItem;

final class ParentChatPopupItem implements PopupDataItem
{
	use ContextCustomer;

	public function __construct(
		private readonly Chat $chat,
	) {}

	public function merge(PopupDataItem $item): self
	{
		return $this;
	}

	public static function getRestEntityName(): string
	{
		return 'parentChat';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return $this->resolveParentChat()?->toRestFormat(['CHAT_SHORT_FORMAT' => true]);
	}

	private function resolveParentChat(): ?Chat
	{
		$parentChat = $this->chat->getParentChat();
		if (!$parentChat?->isExist() || !$parentChat->checkAccess($this->getContext()->getUserId())->isSuccess())
		{
			return null;
		}

		return $parentChat->withContext($this->getContext());
	}
}
