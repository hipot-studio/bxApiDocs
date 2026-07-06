<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Dto\MessageHistory;

use Bitrix\Main\Type\DateTime;

final class ReplyItem implements \JsonSerializable
{
	/**
	 * @param int $messageId ID of the original message.
	 * @param int $authorId Author of the original message.
	 * @param string $text Cleaned text of the original message.
	 * @param DateTime $dateCreate Creation date of the original message.
	 */
	public function __construct(
		public readonly int $messageId,
		public readonly int $authorId,
		public readonly string $text,
		public readonly DateTime $dateCreate,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'messageId' => $this->messageId,
			'authorId' => $this->authorId,
			'text' => $this->text,
			'dateCreate' => $this->dateCreate->format('c'),
		];
	}
}
