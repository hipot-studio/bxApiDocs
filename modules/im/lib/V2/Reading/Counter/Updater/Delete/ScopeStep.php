<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Updater\Delete;

use Bitrix\Im\V2\Chat\Tree\ChatTreeScope;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;

class ScopeStep
{
	private ?array $chatIds = null;
	private ?array $messageIds = null;
	private ?array $excludeMessageIds = null;
	private ?ChatTreeScope $treeScope = null;
	private ?int $toMessageId = null;

	public function __construct(
		private readonly CountersCache $cache,
	) {}

	public function toMessage(Message $message, int $userId): Executor
	{
		$this->resetSelection();
		$this->toMessageId = $message->getId();
		$this->chatIds = [$message->getChatId()];
		$audience = new AudienceStep($this);

		return $audience->forUser($userId);
	}

	public function byTreeScope(ChatTreeScope $treeScope): AudienceStep
	{
		$this->resetSelection();
		$this->treeScope = $treeScope;

		return new AudienceStep($this);
	}

	public function byChat(int $chatId, ?array $excludeMessageIds = null): AudienceStep
	{
		$this->resetSelection();
		$this->chatIds = [$chatId];
		$this->excludeMessageIds = $excludeMessageIds;
		return new AudienceStep($this);
	}

	public function byChats(array $chatIds): AudienceStep
	{
		$this->resetSelection();
		$this->chatIds = $chatIds;
		return new AudienceStep($this);
	}

	public function byMessage(Message $message): AudienceStep
	{
		$this->resetSelection();
		$this->messageIds = [$message->getId()];
		$this->chatIds = [$message->getChatId()];
		return new AudienceStep($this);
	}

	public function byMessages(MessageCollection $messages): AudienceStep
	{
		$this->resetSelection();
		$this->messageIds = $messages->getIds();
		// Note: chat_id filter is redundant for correctness but needed for overflow/cache reset
		$this->chatIds = $messages->getChatIds();
		return new AudienceStep($this);
	}

	public function all(): AudienceStep
	{
		$this->resetSelection();
		return new AudienceStep($this);
	}

	public function getTreeScope(): ?ChatTreeScope
	{
		return $this->treeScope;
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

	public function getExcludeMessageIds(): ?array
	{
		return $this->excludeMessageIds;
	}

	private function resetSelection(): void
	{
		$this->chatIds = null;
		$this->messageIds = null;
		$this->excludeMessageIds = null;
		$this->treeScope = null;
		$this->toMessageId = null;
	}
}
