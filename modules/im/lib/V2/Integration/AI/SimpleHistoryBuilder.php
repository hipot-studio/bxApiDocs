<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Integration\AI\HistoryBuilder\History;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Imbot\Bot\CopilotChatBot;

class SimpleHistoryBuilder
{
	private Message $targetMessage;
	private MessageCollection $messagePool;
	private int $limit;
	private bool $isTruncated = false;

	public function __construct(Message $targetMessage, int $limit)
	{
		$this->messagePool = new MessageCollection();
		$this->addTargetMessage($targetMessage);
		$this->limit = $limit;
	}

	public function build(): History
	{
		$contextMessageIds = $this->getContextMessageIds();
		$this->fillAdditionalMessages();
		$this->messagePool->getFiles()->fillTranscriptions();

		return $this->buildByMessageIds($contextMessageIds);
	}

	private function buildByMessageIds(array $contextMessageIds): History
	{
		$targetMessage = new HistoryBuilder\Message($this->targetMessage, $this->messagePool);
		$lastMessage = null;
		$historyMessages = [];
		$contextMessageIds = array_reverse($contextMessageIds);

		foreach ($contextMessageIds as $contextMessageId)
		{
			$message = $this->messagePool[$contextMessageId] ?? null;
			if (!$message || !$message->getId())
			{
				continue;
			}

			if ($lastMessage && $lastMessage->shouldContinueWith($message))
			{
				$lastMessage->addContinuation($message);
			}
			else
			{
				$lastMessage = new HistoryBuilder\Message($message, $this->messagePool);
				$historyMessages[] = $lastMessage;
			}
		}

		$history = new History($targetMessage, $historyMessages);

		return $history->setTruncated($this->isTruncated);
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
		$currentUserId = $this->targetMessage->getAuthorId();
		$lastMessageId = $this->targetMessage->getMessageId();
		$targetChat = $this->targetMessage->getChat();
		$startId = $this->targetMessage->getChat()->getStartId($currentUserId);

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
				if ($message->getMessageId() < $startId)
				{
					$this->isTruncated = true;

					return $messageIds;
				}

				$lastMessageId = $message->getMessageId();
				$this->addMessageToPool($message);

				if ($targetChat instanceof CopilotChat && $this->hasMentionOtherUser($message))
				{
					continue;
				}

				if ($message->getParams()->isSet(Params::COMPONENT_ID))
				{
					// skip welcome chat message
					if ($message->getParams()->get(Params::COMPONENT_ID)->getValue() == CopilotChatBot::MESSAGE_COMPONENT_START)
					{
						continue;
					}
					// skip error message
					if (
						$message->getParams()->isSet(Params::COMPONENT_PARAMS)
						&& isset($message->getParams()->get(Params::COMPONENT_PARAMS)->getValue()[CopilotChatBot::MESSAGE_PARAMS_ERROR])
					)
					{
						continue;
					}
				}

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

	private function hasMentionOtherUser(Message $message): bool
	{
		if ($message->getAuthorId() === CopilotChatBot::getBotId())
		{
			return false;
		}
		$mentionedUserIds = $message->getMentionedUserIds();
		unset($mentionedUserIds[CopilotChatBot::getBotId()]);

		return count($mentionedUserIds) >= 1;
	}
}
