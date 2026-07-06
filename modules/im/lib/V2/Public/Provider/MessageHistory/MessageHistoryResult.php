<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Provider\MessageHistory;

use Bitrix\Im\V2\Public\Dto\MessageHistory\ChatContextItem;
use Bitrix\Im\V2\Public\Dto\MessageHistory\MessageItem;
use Bitrix\Main\Result;

/**
 * Result of a message history query.
 *
 * On success: contains messages in chronological order (oldest first),
 * chat context, and pagination metadata.
 * On failure: contains errors (e.g. NOT_FOUND).
 */
final class MessageHistoryResult extends Result
{
	/** @var MessageItem[] */
	private array $messages = [];
	private ?ChatContextItem $chatContext = null;
	private bool $hasMore = false;
	private bool $isTruncated = false;

	/**
	 * @param MessageItem[] $messages Messages in chronological order (oldest first).
	 * @return $this
	 */
	public function setMessages(array $messages): self
	{
		$this->messages = $messages;

		return $this;
	}

	/**
	 * @return MessageItem[]
	 */
	public function getMessages(): array
	{
		return $this->messages;
	}

	/**
	 * @param ChatContextItem $chatContext General chat context.
	 * @return $this
	 */
	public function setChatContext(ChatContextItem $chatContext): self
	{
		$this->chatContext = $chatContext;

		return $this;
	}

	/**
	 * @return ChatContextItem|null
	 */
	public function getChatContext(): ?ChatContextItem
	{
		return $this->chatContext;
	}

	/**
	 * @param bool $hasMore Whether older messages exist beyond the current page.
	 * @return $this
	 */
	public function setHasMore(bool $hasMore): self
	{
		$this->hasMore = $hasMore;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function hasMore(): bool
	{
		return $this->hasMore;
	}

	/**
	 * @param bool $isTruncated Whether history is truncated by startId boundary.
	 * @return $this
	 */
	public function setTruncated(bool $isTruncated): self
	{
		$this->isTruncated = $isTruncated;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isTruncated(): bool
	{
		return $this->isTruncated;
	}
}
