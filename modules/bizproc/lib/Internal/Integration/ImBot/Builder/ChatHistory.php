<?php

namespace Bitrix\Bizproc\Internal\Integration\ImBot\Builder;

use Bitrix\Bizproc\Internal\Integration\AI\Enum\HistoryRole;
use Bitrix\Bizproc\Internal\Integration\ImBot\Service\MentionService;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class ChatHistory
{
	private Message $targetMessage;
	private MessageCollection $messagePool;
	private int $limit;
	private int $botId;
	private readonly MentionService $mentionService;

	public static function isSupported(): bool
	{
		return Loader::includeModule('im');
	}

	public function __construct(Message $targetMessage, int $limit, int $botId)
	{
		$this->messagePool = new MessageCollection();
		$this->addTargetMessage($targetMessage);
		$this->limit = $limit;
		$this->botId = $botId;
		$this->mentionService = ServiceLocator::getInstance()->get(MentionService::class);
	}

	/**
	 * @return array{messages: list<array{content: string, role: string}>}
 */
	public function build(): array
	{
		$contextMessageIds = $this->getContextMessageIds();
		$this->fillAdditionalMessages();

		return $this->buildByMessageIds($contextMessageIds);
	}

	/**
	 * @param array<int> $contextMessageIds
	 *
	 * @return array{messages: list<array{content: string, role: string}>}
	 */
	private function buildByMessageIds(array $contextMessageIds): array
	{
		$messages = new \Bitrix\Bizproc\Internal\Integration\AI\DTO\MessageCollection();

		foreach ($contextMessageIds as $contextMessageId)
		{
			$message = $this->messagePool[$contextMessageId] ?? null;
			if (!$message || !$message->getId())
			{
				continue;
			}

			$messages->add($this->getMessageForAi($message));
		}

		return $messages->toArray();
	}

	private function getMessageForAi(Message $message): \Bitrix\Bizproc\Internal\Integration\AI\DTO\Message
	{
		return new \Bitrix\Bizproc\Internal\Integration\AI\DTO\Message(
			content: $this->getMessageText($message),
			role: $this->getRoleByAuthorId($message->getAuthorId())
		);
	}

	private function fillAdditionalMessages(): void
	{
		$replyIds = $this->messagePool->getReplayedMessageIds();
		$uniqueIds = array_diff($replyIds, $this->messagePool->getIds());
		if (empty($uniqueIds))
		{
			return;
		}

		$additionalMessages = new MessageCollection($uniqueIds);
		$this->messagePool->mergeRegistry($additionalMessages);
	}

	private function getContextMessageIds(): array
	{
		$messageIds = [];
		$lastMessageId = $this->targetMessage->getMessageId();
		while (true)
		{
			if (count($messageIds) >= $this->limit)
			{
				return $messageIds;
			}

			$filter = [
				'CHAT_ID' => $this->targetMessage->getChatId(),
				'LAST_ID' => $lastMessageId,
			];

			$order = ['ID' => 'DESC']; // start from newest

			$messages = MessageCollection::find($filter, $order, $this->limit);

			if ($messages->count() === 0)
			{
				return $messageIds;
			}

			$messages->fillParams();

			/** @var Message $message */
			foreach ($messages as $message)
			{
				if (
					$message->isSystem()
					|| $message->getParams()->get(Params::COMPONENT_ID)->getValue() === 'ErrorMessage'
				)
				{
					continue;
				}

				$lastMessageId = $message->getMessageId();
				$this->addMessageToPool($message);

				$messageIds[] = $message->getId();

				if (count($messageIds) >= $this->limit)
				{
					return $messageIds;
				}
			}
		}
	}

	private function addTargetMessage(Message $targetMessage): void
	{
		$this->targetMessage = $targetMessage;
		$this->addMessageToPool($targetMessage);
		if ($targetMessage->hasReply() && $targetMessage->getReplyId())
		{
			$reply = new Message($targetMessage->getReplyId());
			$this->addMessageToPool($reply);
		}
	}

	private function addMessageToPool(Message $message): void
	{
		$this->messagePool->add($message);
	}

	private function getMessageText(Message $message): string
	{
		$text =	$message->getMessage() . $this->getReplyMessageText($message);

		return $this->mentionService->replaceBbMentions($text);
	}

	private function getReplyMessageText(Message $message): string
	{
		$replyId = $message->getReplyId();
		if (!$replyId)
		{
			return '';
		}

		$reply = $this->messagePool[$replyId] ?? null;
		if (!$reply)
		{
			return '';
		}

		$messageContent = $reply->getPreviewMessage();
		$role = $this->getRoleByAuthorId($reply->getAuthorId())->value;

		return "\n___\nQuoted message:\n{$role}\n{$messageContent}\n___\n";
	}

	private function getRoleByAuthorId(int $authorId): HistoryRole
	{
		return $authorId === $this->botId ? HistoryRole::Assistant : HistoryRole::User;
	}
}
