<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Updater\Delete;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;

class ScopeStep
{
	private ?array $chatIds = null;
	private ?array $messageIds = null;
	private ?array $excludeMessageIds = null;
	private ?int $parentId = null;
	private ?int $toMessageId = null;
	private ?Chat\Type $type = null;

	public function __construct(
		private readonly CountersCache $cache,
		private readonly CounterOverflowService $overflowService
	) {}

	public function toMessage(Message $message, int $userId): Executor
	{
		$this->toMessageId = $message->getId();
		$this->chatIds = [$message->getChatId()];
		$audience = new AudienceStep($this);

		return $audience->forUser($userId);
	}

	public function byParent(int $parentId): AudienceStep
	{
		$this->parentId = $parentId;
		return new AudienceStep($this);
	}

	public function byType(Chat\Type $type): AudienceStep
	{
		$this->type = $type;
		return new AudienceStep($this);
	}

	public function byChat(int $chatId, ?array $excludeMessageIds = null): AudienceStep
	{
		$this->chatIds = [$chatId];
		$this->excludeMessageIds = $excludeMessageIds;
		return new AudienceStep($this);
	}

	public function byChats(array $chatIds): AudienceStep
	{
		$this->chatIds = $chatIds;
		return new AudienceStep($this);
	}

	public function byMessage(Message $message): AudienceStep
	{
		$this->messageIds = [$message->getId()];
		$this->chatIds = [$message->getChatId()];
		return new AudienceStep($this);
	}

	public function byMessages(MessageCollection $messages): AudienceStep
	{
		$this->messageIds = $messages->getIds();
		// Note: chat_id filter is redundant for correctness but needed for overflow/cache reset
		$this->chatIds = $messages->getChatIds();
		return new AudienceStep($this);
	}

	public function all(): AudienceStep
	{
		$this->chatIds = null;
		return new AudienceStep($this);
	}

	public function getParentId(): ?int
	{
		return $this->parentId;
	}

	public function getType(): ?Chat\Type
	{
		return $this->type;
	}

	public function getChatIds(): ?array
	{
		return $this->chatIds;
	}

	public function getMessageIds(): ?array
	{
		return $this->messageIds;
	}

	public function getToMessageId(): ?int
	{
		return $this->toMessageId;
	}

	public function getCache(): CountersCache
	{
		return $this->cache;
	}

	public function getOverflowService(): CounterOverflowService
	{
		return $this->overflowService;
	}

	public function getExcludeMessageIds(): ?array
	{
		return $this->excludeMessageIds;
	}
}
