<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Result;

use Bitrix\Im\V2\Rest\RestConvertible;

/**
 * A row of the search output (DTO-01). The whole message payload of im is deliberately kept out: the
 * clients draw a card and jump, they do not render a message from it.
 */
final readonly class MessageSearchItem implements RestConvertible
{
	/**
	 * @param SnippetSegment[] $snippet
	 * @param int $rawPosition Position of the entry in the raw output; a field of the domain, not of DTO-01.
	 */
	public function __construct(
		public int $messageId,
		public int $chatId,
		public string $dialogId,
		public string $date,
		public int $authorId,
		public string $authorName,
		public ?string $chatTitle,
		public string $chatType,
		public array $snippet,
		public int $rawPosition,
		public ?int $parentChatId = null,
		public ?string $parentTitle = null,
		public ?int $postId = null,
	)
	{}

	public static function getRestEntityName(): string
	{
		return 'message';
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'messageId' => $this->messageId,
			'chatId' => $this->chatId,
			'dialogId' => $this->dialogId,
			'date' => $this->date,
			'author' => [
				'id' => $this->authorId,
				'name' => $this->authorName,
			],
			'chat' => [
				'title' => $this->chatTitle,
				'type' => $this->chatType,
			],
			'snippet' => array_map(
				static fn (SnippetSegment $segment): array => $segment->toRestFormat(),
				$this->snippet,
			),
			'parent' => $this->getParentRestFormat(),
		];
	}

	private function getParentRestFormat(): ?array
	{
		if ($this->parentChatId === null)
		{
			return null;
		}

		return [
			'chatId' => $this->parentChatId,
			'dialogId' => 'chat' . $this->parentChatId,
			'title' => $this->parentTitle,
			'postId' => $this->postId,
		];
	}
}
