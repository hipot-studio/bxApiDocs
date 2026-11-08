<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Integration\AI\HistoryBuilder\History;
use Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder\Direction;
use Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder\Options;
use Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder\Window;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Loader;

class SimpleHistoryBuilder
{
	/**
	 * Pages one side of the window is allowed to read. A message dropped in PHP (deleted, an AI
	 * task card, Copilot service noise) does not bring the cursor closer to a full window, so
	 * without a budget a single authorized request could walk the whole history of the chat
	 * synchronously. A side that runs out of pages returns what it collected: the window is
	 * auxiliary, and an incomplete one is reported by Window::isTruncated() instead of failing
	 * the operation.
	 */
	private const MAX_WINDOW_PAGES_PER_SIDE = 4;

	/**
	 * Fields a window block is rendered from. A message row also carries MESSAGE_OUT and
	 * NOTIFY_BUTTONS (both TEXT) plus the NOTIFY_* set, and none of them is ever read here:
	 * params and files of a block come from tables of their own. Same projection the chat
	 * history of the AI assistant is selected with,
	 * @see \Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolContext
	 */
	private const WINDOW_SELECT = ['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'];

	private Message $targetMessage;
	private MessageCollection $messagePool;
	private int $limit;
	private bool $isTruncated = false;

	/** A side of the window gave up before it was full, @see self::MAX_WINDOW_PAGES_PER_SIDE. */
	private bool $windowScanBudgetExhausted = false;

	public function __construct(Message $targetMessage, int $limit)
	{
		$this->messagePool = new MessageCollection();
		$this->addTargetMessage($targetMessage);
		$this->limit = $limit;
	}

	public function build(): History
	{
		$contextMessageIds = $this->getContextMessageIds();
		$this->fillAdditionalMessages($this->messagePool);
		$this->messagePool->getFiles()->fillTranscriptions();

		return $this->buildByMessageIds($contextMessageIds);
	}

	/**
	 * Neighbours of the target message rendered as standalone blocks, one list per side.
	 *
	 * The target message itself never becomes a block: it stays the source of the query and is
	 * passed to the model separately. For Direction::Around the limit applies to each side.
	 *
	 * ACCESS: the window is bounded by the history boundary of Options::$boundaryUserId only. That
	 * is one of the three checks Message::checkAccess() makes, so the caller owes the other two:
	 * it MUST have established that the boundary user may read the chat of the target message
	 * before asking for a window. This method never grants access, it only narrows it.
	 *
	 * The window may come back incomplete - the history boundary cuts it, and so does the page
	 * budget of a side (@see self::MAX_WINDOW_PAGES_PER_SIDE); Window::isTruncated() reports both.
	 *
	 * Options::$withBlocks false asks for the bounds alone: the selection is the same, but nothing
	 * is rendered from it. That is what a caller snapshotting the window for a later replay needs,
	 * and rendering blocks it would throw away is the most expensive part of the whole method.
	 */
	public function buildWindow(Options $options): Window
	{
		$targetId = $this->targetMessage->getMessageId() ?? 0;
		$startId = $options->startId ?? $this->targetMessage->getChat()->getStartId($options->boundaryUserId);

		if ($targetId <= 0)
		{
			return new Window([], [], $targetId, $targetId, $startId);
		}

		$pool = new MessageCollection();
		$backwardMessages = $this->collectWindowSide($options, $startId, false, $pool);
		$forwardMessages = $options->direction === Direction::Around
			? $this->collectWindowSide($options, $startId, true, $pool)
			: []
		;

		[$blocksBefore, $blocksAfter] = $options->withBlocks
			? $this->renderWindowSides($backwardMessages, $forwardMessages, $pool, $options)
			: [[], []]
		;

		$boundIds = array_map(
			static fn (Message $message): int => (int)$message->getMessageId(),
			[...$backwardMessages, ...$forwardMessages]
		);
		$boundIds[] = $targetId;

		return new Window(
			$blocksBefore,
			$blocksAfter,
			$options->fromId ?? min($boundIds),
			$options->toId ?? max($boundIds),
			$startId,
			$this->windowScanBudgetExhausted || ($startId > 0 && count($backwardMessages) < $this->limit),
		);
	}

	/**
	 * @return Message[] Ordered by id, ascending.
	 */
	private function collectWindowSide(
		Options $options,
		int $startId,
		bool $isForward,
		MessageCollection $pool
	): array
	{
		$targetId = (int)$this->targetMessage->getMessageId();
		$lowerBound = max($startId, $options->fromId ?? 0);
		$upperBound = $options->toId;
		$cutByCopilotRules = $this->shouldCutByCopilotRules();

		$filter = ['CHAT_ID' => (int)$this->targetMessage->getChatId()];
		if ($lowerBound > 0)
		{
			$filter['START_ID'] = $lowerBound;
		}
		if (!$options->withSystemMessages)
		{
			$filter['WITHOUT_SYSTEM_MESSAGE'] = true;
		}

		$order = ['ID' => $isForward ? 'ASC' : 'DESC'];
		$cursor = $targetId;
		if (!$isForward && $upperBound !== null && $upperBound < $targetId)
		{
			$cursor = $upperBound + 1;
		}

		$messages = [];
		$pagesRead = 0;

		while (count($messages) < $this->limit)
		{
			if ($pagesRead >= self::MAX_WINDOW_PAGES_PER_SIDE)
			{
				$this->windowScanBudgetExhausted = true;

				break;
			}

			// ask for what is still missing and not for the whole depth again: one message dropped
			// in PHP would otherwise cost another full page to bring a single row back
			$pageSize = $this->limit - count($messages);

			$filter['LAST_ID'] = $cursor;
			$fetched = MessageCollection::find($filter, $order, $pageSize, null, self::WINDOW_SELECT);
			$pagesRead++;

			if ($fetched->count() === 0)
			{
				break;
			}

			$fetched->fillParams();

			/** @var Message $message */
			foreach ($fetched as $message)
			{
				$cursor = (int)$message->getMessageId();

				if ($isForward && $upperBound !== null && $cursor > $upperBound)
				{
					break 2;
				}

				if ($this->shouldSkipInWindow($message, $options, $cutByCopilotRules))
				{
					continue;
				}

				$pool->add($message);
				$messages[] = $message;

				if (count($messages) >= $this->limit)
				{
					break 2;
				}
			}

			if ($fetched->count() < $pageSize)
			{
				break;
			}
		}

		return $isForward ? $messages : array_reverse($messages);
	}

	/**
	 * Everything the bounds of a window do not need: preparing the pool, merging the neighbours
	 * into blocks and rendering them (attachments, mentions and an author read per block).
	 *
	 * Each side is grouped on its own, so no block spans the target message: the two lists are
	 * consecutive only because the target itself never becomes a block, and merging across that
	 * seam would put a message written after the target inside a block standing before it.
	 * The pool is prepared once for both sides - that is the part that reads the database.
	 *
	 * @param Message[] $messagesBefore Chronological order.
	 * @param Message[] $messagesAfter Chronological order.
	 * @return array{0: string[], 1: string[]}
	 */
	private function renderWindowSides(
		array $messagesBefore,
		array $messagesAfter,
		MessageCollection $pool,
		Options $options
	): array
	{
		$this->prepareWindowPool($pool, $options);
		$renderPool = $options->withReplyTargets ? $pool : null;
		$chatId = (int)$this->targetMessage->getChatId();

		return [
			$this->renderWindowSide($messagesBefore, $renderPool, $chatId),
			$this->renderWindowSide($messagesAfter, $renderPool, $chatId),
		];
	}

	/**
	 * @param Message[] $messages Chronological order.
	 * @return string[]
	 */
	private function renderWindowSide(array $messages, ?MessageCollection $renderPool, int $chatId): array
	{
		return array_map(
			fn (HistoryBuilder\Message $block): string => $this->renderWindowBlock($block, $chatId),
			$this->groupIntoBlocks($messages, $renderPool)
		);
	}

	private function prepareWindowPool(MessageCollection $pool, Options $options): void
	{
		if ($options->withReplyTargets)
		{
			$this->fillAdditionalMessages($pool);
		}

		$files = $pool->getFiles();

		if ($options->withNeighbourTranscriptions)
		{
			$files->fillTranscriptions();

			return;
		}

		// mark the files as checked, otherwise every transcribable one loads its transcription itself
		$files->loadParams();
		foreach ($files as $file)
		{
			$file->setCompletedTranscription(null);
		}
	}

	private function renderWindowBlock(HistoryBuilder\Message $block, int $chatId): string
	{
		$text = $block->getMessageBlock();

		return MentionService::getInstance()->replaceBbMentions($text, $chatId) ?? $text;
	}

	private function shouldSkipInWindow(Message $message, Options $options, bool $cutByCopilotRules): bool
	{
		if (!$options->withSystemMessages && $message->isSystem())
		{
			return true;
		}

		if (!$options->withDeletedMessages && $message->getParams()->isSet(Params::IS_DELETED))
		{
			return true;
		}

		if (!$options->withAiTaskCards && $message->getParams()->isSet(Params::AI_TASK_TRIGGER_MESSAGE_ID))
		{
			return true;
		}

		return $cutByCopilotRules
			&& (
				$this->hasMentionOtherUser($message)
				|| $this->isWelcomeMessage($message)
				|| $this->isErrorMessage($message)
			)
		;
	}

	private function buildByMessageIds(array $contextMessageIds): History
	{
		$targetMessage = new HistoryBuilder\Message($this->targetMessage, $this->messagePool);
		$historyMessages = $this->groupIntoBlocks(
			$this->getMessagesFromPool(array_reverse($contextMessageIds)),
			$this->messagePool
		);

		$history = new History($targetMessage, $historyMessages);

		return $history->setTruncated($this->isTruncated);
	}

	/**
	 * @param int[] $messageIds
	 * @return Message[]
	 */
	private function getMessagesFromPool(array $messageIds): array
	{
		$messages = [];

		foreach ($messageIds as $messageId)
		{
			$message = $this->messagePool[$messageId] ?? null;
			if ($message && $message->getId())
			{
				$messages[] = $message;
			}
		}

		return $messages;
	}

	/**
	 * Merges consecutive messages of the same author into one block.
	 *
	 * @param Message[] $messages Chronological order.
	 * @return HistoryBuilder\Message[]
	 */
	private function groupIntoBlocks(array $messages, ?MessageCollection $messagePool): array
	{
		$blocks = [];
		$lastBlock = null;

		foreach ($messages as $message)
		{
			if ($lastBlock && $lastBlock->shouldContinueWith($message))
			{
				$lastBlock->addContinuation($message);

				continue;
			}

			$lastBlock = new HistoryBuilder\Message($message, $messagePool);
			$blocks[] = $lastBlock;
		}

		return $blocks;
	}

	private function fillAdditionalMessages(MessageCollection $messagePool): void
	{
		$replyIds = $messagePool->getReplayedMessageIds();
		$uniqueIds = array_diff($replyIds, $messagePool->getIds());
		if (empty($uniqueIds))
		{
			return;
		}

		$additionalMessages = new MessageCollection($uniqueIds);
		$messagePool->mergeRegistry($additionalMessages);
	}

	private function getContextMessageIds(): array
	{
		$messageIds = [];
		$currentUserId = $this->targetMessage->getAuthorId();
		$lastMessageId = $this->targetMessage->getMessageId();
		$targetChat = $this->targetMessage->getChat();
		$startId = $this->targetMessage->getChat()->getStartId($currentUserId);
		$cutByCopilotRules = Loader::includeModule('imbot');

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

				if ($cutByCopilotRules)
				{
					if ($targetChat instanceof CopilotChat && $this->hasMentionOtherUser($message))
					{
						continue;
					}

					if ($this->isWelcomeMessage($message) || $this->isErrorMessage($message))
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

	/**
	 * Copilot service messages are recognised by imbot constants, so the filter only makes sense
	 * inside a Copilot chat on a portal where imbot is installed.
	 */
	private function shouldCutByCopilotRules(): bool
	{
		return $this->targetMessage->getChat() instanceof CopilotChat && Loader::includeModule('imbot');
	}

	private function isWelcomeMessage(Message $message): bool
	{
		return $message->getParams()->isSet(Params::COMPONENT_ID)
			&& $message->getParams()->get(Params::COMPONENT_ID)->getValue() === CopilotChatBot::MESSAGE_COMPONENT_START
		;
	}

	private function isErrorMessage(Message $message): bool
	{
		return $message->getParams()->isSet(Params::COMPONENT_ID)
			&& $message->getParams()->isSet(Params::COMPONENT_PARAMS)
			&& isset($message->getParams()->get(Params::COMPONENT_PARAMS)->getValue()[CopilotChatBot::MESSAGE_PARAMS_ERROR])
		;
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
