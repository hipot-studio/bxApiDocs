<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Provider\MessageHistory;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\CommentChat;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\NullChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Im\V2\Public\Dto\MessageHistory\AttachItem;
use Bitrix\Im\V2\Public\Dto\MessageHistory\ChatContextItem;
use Bitrix\Im\V2\Public\Dto\MessageHistory\FileItem;
use Bitrix\Im\V2\Public\Dto\MessageHistory\MessageItem;
use Bitrix\Im\V2\Public\Dto\MessageHistory\ReplyItem;

/**
 * Public provider for chat message history.
 *
 * Returns messages as lightweight DTOs with files, replies, attachments
 * and transcriptions. Respects startId boundary when targetMessageId is provided.
 *
 * Access control is the caller's responsibility.
 *
 * This is a specialized provider (not a canonical MessageProvider).
 * See architecture-review-v3.md for rationale.
 */
final class MessageHistoryProvider
{
	private int $targetMessageAuthorId = 0;
	private int $startId = 0;
	private bool $hasMore = false;
	private bool $isTruncated = false;

	/**
	 * Returns a list of chat messages as lightweight DTOs.
	 *
	 * Messages are returned in chronological order (oldest first).
	 * System messages are excluded.
	 *
	 * @param MessageHistoryFilter $filter Query parameters.
	 * @return MessageHistoryResult Messages, chat context and pagination metadata.
	 */
	public function getList(MessageHistoryFilter $filter): MessageHistoryResult
	{
		$result = new MessageHistoryResult();

		$chat = Chat::getInstance($filter->getChatId());
		if (!$chat->getChatId())
		{
			$result->addError(new ChatError(ChatError::NOT_FOUND));

			return $result;
		}

		$messages = $this->loadMessages($chat, $filter);
		if ($messages === null)
		{
			$result->addError(new MessageError(MessageError::NOT_FOUND));

			return $result;
		}

		$replyMessages = $this
			->loadReplyMessages($messages)
			->filter(fn(Message $m): bool => $m->getId() >= $this->startId)
		;

		$items = $this->buildMessageItems($messages, $replyMessages);

		$result
			->setMessages(array_reverse($items))
			->setChatContext($this->buildChatContext($chat))
			->setHasMore($this->hasMore)
			->setTruncated($this->isTruncated)
		;

		return $result;
	}

	private function loadTargetMessage(MessageHistoryFilter $filter): ?Message
	{
		$targetMessage = new Message($filter->getTargetMessageId());
		if (!$targetMessage->getId() || $targetMessage->getChatId() !== $filter->getChatId())
		{
			return null;
		}

		return $targetMessage;
	}

	private function loadMessages(Chat $chat, MessageHistoryFilter $filter): ?MessageCollection
	{
		$targetMessage = $this->loadTargetMessage($filter);
		if ($targetMessage === null)
		{
			return null;
		}

		$this->targetMessageAuthorId = $targetMessage->getAuthorId();
		$this->startId = $chat->getStartId($this->targetMessageAuthorId);
		$this->isTruncated = $this->startId > 0;

		$messages = new MessageCollection();
		$cursor = $filter->getBeforeMessageId();

		while ($messages->count() < $filter->getLimit())
		{
			$fetched = $this->fetchMessages($filter->getChatId(), $this->startId, $cursor, $filter->getLimit());

			if ($fetched->count() === 0)
			{
				$this->hasMore = false;

				break;
			}

			$fetched->fillParams();

			foreach ($fetched as $message)
			{
				$cursor = $message->getId();

				if ($this->shouldSkip($chat, $message))
				{
					continue;
				}

				$messages->add($message);

				if ($messages->count() >= $filter->getLimit())
				{
					$this->hasMore = true;

					break 2;
				}
			}

			if ($fetched->count() < $filter->getLimit())
			{
				$this->hasMore = false;

				break;
			}
		}

		$messages->fillFiles();
		$messages->getFiles()->fillTranscriptions();

		return $messages;
	}

	private function fetchMessages(int $chatId, int $startId, ?int $cursor, int $limit): MessageCollection
	{
		$queryFilter = [
			'CHAT_ID' => $chatId,
		];

		if ($startId > 0)
		{
			$queryFilter['START_ID'] = $startId;
		}

		if ($cursor !== null)
		{
			$queryFilter['LAST_ID'] = $cursor;
		}

		return MessageCollection::find(
			$queryFilter,
			['ID' => 'DESC'],
			$limit,
		);
	}

	private function loadReplyMessages(MessageCollection $messages): MessageCollection
	{
		$replyIds = $messages->getReplayedMessageIds();
		$uniqueIds = array_diff($replyIds, $messages->getIds());

		if (empty($uniqueIds))
		{
			return new MessageCollection();
		}

		return new MessageCollection($uniqueIds);
	}

	/**
	 * @return MessageItem[]
	 */
	private function buildMessageItems(
		MessageCollection $messages,
		MessageCollection $replyMessages,
	): array
	{
		$result = [];

		foreach ($messages as $message)
		{
			$result[] = new MessageItem(
				id: $message->getId(),
				chatId: $message->getChatId(),
				authorId: $message->getAuthorId(),
				text: $this->prepareText($message),
				dateCreate: $message->getDateCreate(),
				reply: $this->buildReply($message, $messages, $replyMessages),
				files: $this->buildFiles($message),
				attach: $this->buildAttach($message),
			);
		}

		return $result;
	}

	private function shouldSkip(Chat $chat, Message $message): bool
	{
		return $message->isSystem()
			|| $message->getParams()->isSet(Params::IS_DELETED)
			|| $this->isWelcomeMessage($message)
			|| $this->isErrorMessage($message)
			|| ($chat instanceof CopilotChat && $this->hasMentionOtherUser($message))
		;
	}

	private function isWelcomeMessage(Message $message): bool
	{
		if (!$message->getParams()->isSet(Params::COMPONENT_ID))
		{
			return false;
		}

		return $message->getParams()->get(Params::COMPONENT_ID)->getValue() === CopilotChatBot::MESSAGE_COMPONENT_START;
	}

	private function isErrorMessage(Message $message): bool
	{
		if (!$message->getParams()->isSet(Params::COMPONENT_PARAMS))
		{
			return false;
		}

		return isset($message->getParams()->get(Params::COMPONENT_PARAMS)->getValue()[CopilotChatBot::MESSAGE_PARAMS_ERROR]);
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

	private function prepareText(Message $message): string
	{
		return \Bitrix\Im\Text::removeBbCodes($message->getMessage() ?? '');
	}

	private function buildReply(
		Message $message,
		MessageCollection $messages,
		MessageCollection $replyMessages,
	): ?ReplyItem
	{
		$replyId = $message->getReplyId();
		if (!$replyId)
		{
			return null;
		}

		$replyMessage = $messages[$replyId] ?? $replyMessages[$replyId] ?? null;
		if ($replyMessage === null)
		{
			return null;
		}

		return new ReplyItem(
			messageId: $replyMessage->getId(),
			authorId: $replyMessage->getAuthorId(),
			text: $this->prepareText($replyMessage),
			dateCreate: $replyMessage->getDateCreate(),
		);
	}

	private function buildFiles(Message $message): array
	{
		$files = [];

		foreach ($message->getFiles() as $fileItem)
		{
			$diskFile = $fileItem->getDiskFile();
			if ($diskFile === null)
			{
				continue;
			}

			$transcriptionText = $fileItem->getCompletedTranscription()?->transcriptText;

			$files[] = new FileItem(
				diskId: (int)$diskFile->getId(),
				name: $diskFile->getName(),
				size: (int)$diskFile->getSize(),
				type: $fileItem->getContentType() ?? '',
				transcriptionText: $transcriptionText,
			);
		}

		return $files;
	}

	private function buildAttach(Message $message): ?AttachItem
	{
		$attachData = $message->getAttach()->toRestFormat();

		if (empty($attachData))
		{
			return null;
		}

		return new AttachItem(blocks: $attachData);
	}

	private function buildChatContext(Chat $chat): ChatContextItem
	{
		return new ChatContextItem(
			chatId: $chat->getChatId(),
			title: $this->resolveChatTitle($chat),
			type: $chat->getType(),
		);
	}

	private function resolveChatTitle(Chat $chat): string
	{
		if ($chat instanceof CommentChat && !($chat->getParentChat() instanceof NullChat))
		{
			return $chat->getParentChat()->getDisplayedTitle() ?? '';
		}

		return $chat->withContextUser($this->targetMessageAuthorId)->getDisplayedTitle() ?? '';
	}
}
