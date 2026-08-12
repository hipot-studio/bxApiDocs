<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

final readonly class PreviewSource
{
	public function __construct(
		public int $sourceChatId,
		public int $messageId,
		public bool $isMainChat,
	) {}

	public static function mainChat(int $collabChatId, int $messageId): self
	{
		return new self(
			sourceChatId: $collabChatId,
			messageId: $messageId,
			isMainChat: true,
		);
	}
}
