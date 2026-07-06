<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Dto\MessageHistory;

use Bitrix\Main\Type\DateTime;

final class MessageItem implements \JsonSerializable
{
	/**
	 * @param int $id Message ID.
	 * @param int $chatId Chat ID.
	 * @param int $authorId Author user ID.
	 * @param string $text Cleaned message text.
	 * @param DateTime $dateCreate Message creation date.
	 * @param ReplyItem|null $reply Original message data if this is a reply, null otherwise.
	 * @param FileItem[] $files Attached files, empty array if none.
	 * @param AttachItem|null $attach Rich-content attachment data, null if none.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $chatId,
		public readonly int $authorId,
		public readonly string $text,
		public readonly DateTime $dateCreate,
		public readonly ?ReplyItem $reply,
		public readonly array $files,
		public readonly ?AttachItem $attach,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'chatId' => $this->chatId,
			'authorId' => $this->authorId,
			'text' => $this->text,
			'dateCreate' => $this->dateCreate->format('c'),
			'reply' => $this->reply,
			'files' => $this->files,
			'attach' => $this->attach,
		];
	}
}
