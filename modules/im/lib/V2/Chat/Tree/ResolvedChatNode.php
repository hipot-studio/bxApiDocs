<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

final readonly class ResolvedChatNode
{
	public function __construct(
		public int $chatId,
		public int $parentChatId,
		public bool $isMuted,
		public string $chatType,
		public ?string $entityType,
		// No recent row for this user => the chat is hidden from the recent list.
		public bool $isHidden = false,
	) {}
}
