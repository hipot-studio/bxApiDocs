<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\Model\ChatParamTable;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageDisappearingTable;
use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\Notify;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Shared hygiene rules for indexing, search output and index cleanup.
 */
class HygieneRegistry
{
	/**
	 * Transitions after which a message may no longer be found. Deletion covers the soft and the
	 * complete mode alike, and with them the expiry of a disappearing message.
	 */
	public const
		TRANSITION_DELETE = 'delete',
		TRANSITION_MARKED_DISAPPEARING = 'markedDisappearing'
	;

	/** Cards the messenger writes on its own behalf when a chat is created. */
	private const SERVICE_CARD_COMPONENT_IDS = [
		'ChatCreationMessage',
		'OwnChatCreationMessage',
		'GeneralChatCreationMessage',
	];

	private const
		DELETED_PARAM = 'IS_DELETED',
		DELETED_PARAM_VALUE = 'Y'
	;

	/** Portions of the walks standing above one another; the answers below live as long as the outermost. */
	private int $openPortions = 0;

	/** @var array<int, bool> Messages of the open portion the disappearing table names, and those it does not. */
	private array $disappearingAnswers = [];

	/** @var array<int, bool> Parent posts of the open portion the deletion has marked, and those it has not. */
	private array $deletedPostAnswers = [];

	public function __construct(
		private readonly OpenLinesMessageRegistry $openLinesMessageRegistry,
	)
	{}

	/**
	 * Entrance of the pipeline: one message at a time, loading what the rule needs on its own.
	 */
	public function isIndexable(Message $message, Chat $chat): bool
	{
		$messageId = $message->getMessageId() ?? 0;

		return $this->matchesRule(
			$message,
			$chat,
			$this->deletedPostsOf($this->postIdsOf([$chat])),
			$this->disappearingOf([$messageId]),
		);
	}

	/**
	 * Opens the scope the answers of the rule are read in, and closes it with the portion that owns it.
	 *
	 * Outside a portion nothing is remembered: the write path indexes one message per job, and an answer
	 * kept past it would let a message deleted meanwhile through on the strength of a stale read. A walk
	 * runs in a process of its own and reads a portion at a time, so there the answers are the portion's.
	 */
	public function openPortion(): void
	{
		$this->openPortions++;
	}

	public function closePortion(): void
	{
		$this->openPortions = max(0, $this->openPortions - 1);
		if ($this->openPortions === 0)
		{
			$this->disappearingAnswers = [];
			$this->deletedPostAnswers = [];
		}
	}

	/**
	 * Reads the disappearing marks of a whole portion at once. Called by the portion itself, so that a
	 * walk asking the rule message by message pays one query and not one per message.
	 *
	 * @param int[] $messageIds
	 */
	public function rememberMessages(array $messageIds): void
	{
		if ($this->openPortions === 0)
		{
			return;
		}

		$this->disappearingOf($messageIds);
	}

	/**
	 * The same for the deletion marks of the posts the comments of a portion belong to. Asked message
	 * by message, the rule reads the post of the comment it holds and nothing else, so a portion of
	 * comments of as many posts costs a query per comment: the memo only helps the comments sharing a
	 * post.
	 *
	 * @param int[] $postIds
	 */
	public function rememberPosts(array $postIds): void
	{
		if ($this->openPortions === 0)
		{
			return;
		}

		$this->deletedPostsOf($postIds);
	}

	/**
	 * Batched hygiene checks for search output and index cleanup.
	 *
	 * The rule is the one below; only the way the data reaches it differs. Loading per message over a
	 * portion of a thousand would cost thousands of queries a step, so messages, parameters, chats and
	 * the marks of their parent posts are loaded in batches.
	 *
	 * The portion is where the chats it read are released: a step of the cleanup stands in the same
	 * hit of the agents as the other walks of the domain, and a chat left behind by one of them is
	 * read by the next as it was.
	 *
	 * @param int[] $messageIds
	 * @return int[] Messages whose entry the index may not keep, the ones already gone included.
	 */
	public function nonIndexableOf(array $messageIds): array
	{
		$messageIds = array_values(array_unique(array_filter(
			array_map('intval', $messageIds),
			static fn (int $messageId): bool => $messageId > 0,
		)));
		if ($messageIds === [])
		{
			return [];
		}

		$portion = new WalkPortion();

		try
		{
			return $this->collectNonIndexable($messageIds, $portion);
		}
		finally
		{
			$portion->release();
		}
	}

	/**
	 * @param int[] $messageIds
	 * @return int[]
	 */
	private function collectNonIndexable(array $messageIds, WalkPortion $portion): array
	{
		$alive = $this->loadMessages($messageIds);
		$chats = $this->loadChats($alive);
		$deletedPosts = $this->deletedPostsOf($this->postIdsOf($chats));
		$disappearing = $this->disappearingOf(array_keys($alive));
		$copilotDrafts = $this->loadCopilotDrafts($chats);

		foreach ($chats as $chat)
		{
			$portion->addChat($chat);
		}

		$doomed = [];
		foreach ($messageIds as $messageId)
		{
			$message = $alive[$messageId] ?? null;
			if ($message === null)
			{
				// the message is gone while its entry stayed: the same rubbish, reached by another road
				$doomed[] = $messageId;

				continue;
			}

			if (!$this->matchesRule(
				$message,
				$chats[$message->getChatId() ?? 0],
				$deletedPosts,
				$disappearing,
				$copilotDrafts,
			))
			{
				$doomed[] = $messageId;
			}
		}

		return $doomed;
	}

	/**
	 * The same rule applies to live search candidates, new entries and index cleanup.
	 *
	 * @param array<int, true> $deletedPosts Posts marked as softly deleted, as a set.
	 * @param array<int, true> $disappearing Messages marked to disappear one by one, as a set.
	 * @param array<int, true>|null $copilotDrafts Draft chats loaded for a batch; null for a single message.
	 */
	private function matchesRule(
		Message $message,
		Chat $chat,
		array $deletedPosts,
		array $disappearing,
		?array $copilotDrafts = null,
	): bool
	{
		if ($this->isSystemOrNotification($message))
		{
			return false;
		}

		// the rules of the chat come first: they answer without loading the params of the message
		if ($chat->getMessagesAutoDeleteDelay() > 0)
		{
			return false;
		}

		// a chat without the setting still lets a message of its own be marked to disappear, and that
		// mark outlives the transition that took the entry off: without it here the next rewrite, the
		// walk of the archive or a rebuild of the general index would write the message back
		if (isset($disappearing[$message->getMessageId() ?? 0]))
		{
			return false;
		}

		$isCopilotDraft = $copilotDrafts === null
			? $chat instanceof Chat\CopilotChat && $chat->isDraftChat()
			: isset($copilotDrafts[$chat->getId()])
		;
		if ($isCopilotDraft)
		{
			return false;
		}

		$params = $message->getParams();

		if ($params->get(Message\Params::IS_DELETED)->getValue() === true)
		{
			return false;
		}

		if ($this->openLinesMessageRegistry->isServiceMessage($params) || $this->isServiceCard($params))
		{
			return false;
		}

		if (!$this->hasSearchableText($message, $params))
		{
			return false;
		}

		return !$this->isCommentOfDeletedPost($chat, $deletedPosts);
	}

	/**
	 * The core of the rule about the comments of a deleted post, and the batch way into the registry:
	 * the hygiene stepper asks about the posts of a whole portion at once, the pipeline about the one
	 * post of the comment it is indexing.
	 *
	 * @param int[] $postIds
	 * @return array<int, true> Posts marked as softly deleted, as a set.
	 */
	public function deletedPostsOf(array $postIds): array
	{
		return $this->answerWithinPortion($postIds, $this->deletedPostAnswers, static function (array $ids): array {
			return MessageParamTable::query()
				->setSelect(['MESSAGE_ID'])
				->whereIn('MESSAGE_ID', $ids)
				->where('PARAM_NAME', self::DELETED_PARAM)
				->where('PARAM_VALUE', self::DELETED_PARAM_VALUE)
				->fetchAll()
			;
		});
	}

	/**
	 * Messages marked to disappear one by one, whatever the chat they stand in is set to.
	 *
	 * @param int[] $messageIds
	 * @return array<int, true> Messages awaiting their disappearance, as a set.
	 */
	private function disappearingOf(array $messageIds): array
	{
		return $this->answerWithinPortion($messageIds, $this->disappearingAnswers, static function (array $ids): array {
			return MessageDisappearingTable::query()
				->setSelect(['MESSAGE_ID'])
				->whereIn('MESSAGE_ID', $ids)
				->fetchAll()
			;
		});
	}

	/**
	 * One query per portion instead of one per message, and the negative answers are kept beside the
	 * positive ones: a walk asks the rule message by message about the very same parent post, and
	 * 'this post is not deleted' costs the same query as 'it is'.
	 *
	 * Outside a portion nothing is kept. The write path indexes one message per job, so it would gain
	 * nothing, while an answer left over from an earlier hit of the same process would let a message
	 * through on the strength of a state that has since changed.
	 *
	 * @param int[] $ids
	 * @param array<int, bool> $answers Memo of the open portion.
	 * @param \Closure(int[] $ids): array<array{MESSAGE_ID: mixed}> $reader Rows the ids were named by.
	 * @return array<int, true> Ids the reader named, as a set.
	 */
	private function answerWithinPortion(array $ids, array &$answers, \Closure $reader): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn (int $id): bool => $id > 0,
		)));
		if ($ids === [])
		{
			return [];
		}

		$unanswered = array_values(array_diff($ids, array_keys($answers)));
		if ($unanswered === [])
		{
			return $this->pickNamed($ids, $answers);
		}

		$named = [];
		foreach ($reader($unanswered) as $row)
		{
			$named[(int)$row['MESSAGE_ID']] = true;
		}

		if ($this->openPortions === 0)
		{
			return $named;
		}

		foreach ($unanswered as $id)
		{
			$answers[$id] = isset($named[$id]);
		}

		return $this->pickNamed($ids, $answers);
	}

	/**
	 * @param int[] $ids
	 * @param array<int, bool> $answers
	 * @return array<int, true>
	 */
	private function pickNamed(array $ids, array $answers): array
	{
		$picked = [];
		foreach ($ids as $id)
		{
			if ($answers[$id] ?? false)
			{
				$picked[$id] = true;
			}
		}

		return $picked;
	}

	/**
	 * Second side of the rule: the points that publish a transition name the transition and stay out
	 * of the index, so what a transition costs the index is written down here alone.
	 */
	public function deindexOnTransition(string $transition, int ...$messageIds): void
	{
		if ($messageIds === [])
		{
			return;
		}

		// the index service is built on top of this registry, hence resolved at call time and not injected
		$indexService = ServiceLocator::getInstance()->get(IndexService::class);

		if ($transition === self::TRANSITION_MARKED_DISAPPEARING)
		{
			foreach ($messageIds as $messageId)
			{
				$indexService->onMessageDisappear($messageId);
			}

			return;
		}

		$indexService->onMessageDelete($messageIds);
	}

	private function isSystemOrNotification(Message $message): bool
	{
		return $message->getAuthorId() === 0
			|| $message->getNotifyType() !== \IM_NOTIFY_MESSAGE
			|| $message->getNotifyEvent() === Notify::EVENT_PRIVATE_SYSTEM
		;
	}

	/**
	 * A softly deleted post keeps its own row and its thread alive, and the thread goes on taking new
	 * comments. Every such comment would enter the index forever unseen - the facade drops a comment
	 * whose post is gone or deleted - so the entrance closes both the new ones and what has piled up.
	 */
	private function isCommentOfDeletedPost(Chat $chat, array $deletedPosts): bool
	{
		if (!$chat instanceof Chat\CommentChat)
		{
			return false;
		}

		$postId = $chat->getParentMessageId();

		return $postId > 0 && isset($deletedPosts[$postId]);
	}

	/**
	 * Posts of the threads among the given chats. Asking about no post makes no query at all, which is
	 * what a chat that is not a thread pays.
	 *
	 * @param iterable<Chat> $chats
	 * @return int[]
	 */
	private function postIdsOf(iterable $chats): array
	{
		$postIds = [];
		foreach ($chats as $chat)
		{
			if ($chat instanceof Chat\CommentChat && $chat->getParentMessageId() > 0)
			{
				$postIds[] = $chat->getParentMessageId();
			}
		}

		return $postIds;
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, Message> Live messages of the portion by id, their parameters already filled.
	 */
	private function loadMessages(array $messageIds): array
	{
		$collection = new MessageCollection($messageIds);
		$collection->fillParams();

		$messages = [];
		foreach ($collection as $message)
		{
			$messageId = $message->getMessageId() ?? 0;
			if ($messageId > 0)
			{
				$messages[$messageId] = $message;
			}
		}

		return $messages;
	}

	/**
	 * @param array<int, Message> $messages
	 * @return array<int, Chat> Chat snapshots for the current batch, without populating the shared cache.
	 */
	private function loadChats(array $messages): array
	{
		$chatIds = [];
		foreach ($messages as $message)
		{
			$chatIds[$message->getChatId() ?? 0] = true;
		}

		$chats = array_fill_keys(array_keys($chatIds), new Chat\NullChat());
		foreach (array_chunk(array_keys($chatIds), ChatFactory::CHATS_FETCH_CHUNK_SIZE) as $ids)
		{
			$rows = ChatTable::query()->setSelect(['*'])->whereIn('ID', $ids)->exec();
			while ($row = $rows->fetch())
			{
				// Shared-cache serialization reads every chat's parameters; hygiene only needs the snapshot.
				$chats[(int)$row['ID']] = ChatFactory::getInstance()->initChat($row);
			}
		}

		return $chats;
	}

	/**
	 * @param array<int, Chat> $chats
	 * @return array<int, true>
	 */
	private function loadCopilotDrafts(array $chats): array
	{
		$chatIds = [];
		foreach ($chats as $chat)
		{
			if ($chat instanceof Chat\CopilotChat)
			{
				$chatIds[] = $chat->getId();
			}
		}
		if ($chatIds === [])
		{
			return [];
		}

		$rows = ChatParamTable::query()
			->setSelect(['CHAT_ID'])
			->whereIn('CHAT_ID', $chatIds)
			->where('PARAM_NAME', Chat\Param\Params::IS_COPILOT_DRAFT)
			->fetchAll()
		;

		$drafts = [];
		foreach ($rows as $row)
		{
			$drafts[(int)$row['CHAT_ID']] = true;
		}

		return $drafts;
	}

	private function isServiceCard(Message\Params $params): bool
	{
		$componentId = (string)$params->get(Message\Params::COMPONENT_ID)->getValue();

		return in_array($componentId, self::SERVICE_CARD_COMPONENT_IDS, true);
	}

	/**
	 * A message with no text of its own and no text in its attachments has nothing to be found by:
	 * file names are deliberately kept out of the body.
	 *
	 * The searchable text is asked for and not the source: a text made of formatting alone is not empty
	 * as it is stored and cleans down to nothing once the BB codes come off. The builder of the body
	 * answers, so the entry and the rule cannot disagree about what the message would be found by.
	 */
	private function hasSearchableText(Message $message, Message\Params $params): bool
	{
		if (ContentBuilder::buildSearchableText($message->getMessage() ?? '') !== '')
		{
			return true;
		}

		return $this->hasAttachText($params);
	}

	private function hasAttachText(Message\Params $params): bool
	{
		if (!$params->isSet(Message\Params::ATTACH))
		{
			return false;
		}

		foreach ($params->get(Message\Params::ATTACH)->getValue() as $attach)
		{
			foreach (\CIMMessageParamAttach::GetTextForIndex($attach) as $text)
			{
				if (trim((string)$text) !== '')
				{
					return true;
				}
			}
		}

		return false;
	}
}
