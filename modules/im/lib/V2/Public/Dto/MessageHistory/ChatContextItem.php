<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Dto\MessageHistory;

final class ChatContextItem implements \JsonSerializable
{
	/**
	 * @param int $chatId Chat ID.
	 * @param string $title Chat title.
	 * @param string $type Chat type identifier (e.g. "chat", "open", "channel").
	 */
	public function __construct(
		public readonly int $chatId,
		public readonly string $title,
		public readonly string $type,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'chatId' => $this->chatId,
			'title' => $this->title,
			'type' => $this->type,
		];
	}
}
