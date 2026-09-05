<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Provider\MessageHistory;

/**
 * Immutable filter for querying message history.
 */
final class MessageHistoryFilter
{
	private const MAX_LIMIT = 300;
	private const DEFAULT_LIMIT = 50;

	private int $chatId;
	private int $targetMessageId;
	private int $limit;
	private ?int $beforeMessageId;
	private bool $includeSystem;

	/**
	 * @param int $chatId Target chat ID.
	 * @param int $targetMessageId Anchor message ID; its author determines the startId visibility boundary.
	 * @param int $limit Maximum number of messages to return.
	 * @param int|null $beforeMessageId Return messages older than this ID (cursor pagination).
	 * @param bool $includeSystem When true, system messages are included in the result (opt-in, default false).
	 */
	public function __construct(
		int $chatId,
		int $targetMessageId,
		int $limit = self::DEFAULT_LIMIT,
		?int $beforeMessageId = null,
		bool $includeSystem = false,
	)
	{
		$this->chatId = $chatId;
		$this->targetMessageId = $targetMessageId;
		$this->limit = min(max($limit, 1), self::MAX_LIMIT);
		$this->beforeMessageId = $beforeMessageId;
		$this->includeSystem = $includeSystem;
	}

	public function getChatId(): int
	{
		return $this->chatId;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}

	public function getBeforeMessageId(): ?int
	{
		return $this->beforeMessageId;
	}

	public function getTargetMessageId(): int
	{
		return $this->targetMessageId;
	}

	public function shouldIncludeSystem(): bool
	{
		return $this->includeSystem;
	}
}
