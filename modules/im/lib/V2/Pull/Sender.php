<?php

namespace Bitrix\Im\V2\Pull;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\TariffLimit\Limit;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Pull\Error;

/**
 * @internal
 */
class Sender
{
	/** @var array<int, bool> the border of a root post, weighed once per post */
	private array $postsWithinHistoryLimit = [];

	/**
	 * @internal
	 * @see \Bitrix\Im\V2\Pull\Event::send()
	 * @param Event $event
	 * @return Result
	 */
	public function send(Event $event): Result
	{
		if (!Loader::includeModule('pull'))
		{
			return (new Result())->addError(new \Bitrix\Im\V2\Error(\Bitrix\Im\V2\Error::PULL_NOT_INSTALLED));
		}

		$results = [];

		if ($event->isGlobal())
		{
			return $this->sendGlobal($event);
		}

		$chat = $event->getTarget();

		// Both channels of the event carry the same payload, and a discussion past the border of its
		// root post is closed for the recipients of either: the question is asked once, ahead of both.
		// Only a discussion has a root post to ask it about - sendToPostCommentSubscribers() keeps a
		// copy of it for the legacy callers, which hold the ids and no chat object at all.
		if ($chat instanceof Chat\CommentChat && !$this->isPostWithinHistoryLimit($chat->getParentMessageId()))
		{
			return new Result();
		}

		if ($chat !== null && !$event->shouldSendToOnlySpecificRecipients())
		{
			$results = $this->processPublicSending($chat, $event);
		}

		foreach ($event->getPullByUsers() as $group)
		{
			$results[] = $this->sendPull($group->getRecipients(), $group->getParams());
		}

		if ($this->shouldSendMobilePush($chat, $event))
		{
			foreach ($event->getMobilePushByUsers() as $group)
			{
				$results[] = $this->sendPush($group->getRecipients(), $group->getParams());
			}
		}

		if ($event->shouldSendImmediately())
		{
			$results[] = $this->sendImmediately();
		}

		return Result::merge(...$results);
	}

	protected function processPublicSending(Chat $chat, Event $event): array
	{
		$results = [];
		$pull = $event->getPullForPublic();

		if ($chat->needToSendPublicPull())
		{
			$results[] = $this->sendByTag('IM_PUBLIC_'. $chat->getChatId(), $pull);
		}
		if ($chat->getType() === Chat::IM_TYPE_OPEN_CHANNEL && $event->shouldSendSharedPull())
		{
			$results[] = $this->sendSharedPull($pull);
		}
		if ($chat->getType() === Chat::IM_TYPE_COMMENT)
		{
			$results[] = $this->sendToCommentSubscribers($chat, $pull);
		}

		return $results;
	}

	/**
	 * A discussion is readable exactly as far as its root post is: Message::checkAccess() refuses a post
	 * older than the START_ID of the reader in the parent chat, and the list of discussions holds that
	 * border. The tag of the parent knows nothing of it - one subscription covers every discussion of
	 * the chat and carries the same payload to everybody - so the reader whose history starts past the
	 * root post is cut out here, where the event is addressed.
	 *
	 * The single place the comment tag is published from. Ten call sites wrote it on their own before -
	 * the reply, its edit and message params, reactions, deletions, link previews, the announcement of a
	 * voice recording and three legacy paths - and a border kept in ten copies stops being a border with
	 * the first copy that is edited.
	 */
	public function sendToCommentSubscribers(Chat $chat, array $pull): Result
	{
		return $this->sendToPostCommentSubscribers(
			(int)$chat->getParentChatId(),
			$chat->getParentMessageId(),
			$pull
		);
	}

	/**
	 * The same seam for the callers holding the ids and no chat object: the legacy paths read them off the
	 * row of the chat they have already fetched, and the deferred link push keeps them from the moment its
	 * event was built.
	 */
	public function sendToPostCommentSubscribers(int $parentChatId, int $postId, array $pull): Result
	{
		if (!Loader::includeModule('pull'))
		{
			return (new Result())->addError(new \Bitrix\Im\V2\Error(\Bitrix\Im\V2\Error::PULL_NOT_INSTALLED));
		}

		// with no root post to compare against there is no border to hold, so nothing is published
		if ($parentChatId <= 0 || $postId <= 0)
		{
			return new Result();
		}

		if (!$this->isPostWithinHistoryLimit($postId))
		{
			return new Result();
		}

		// The border the chat holds for everybody at once: a post behind it is unreachable for members and
		// for the watchers of an open chat alike, and the discussion under it is on its way out with the
		// scheduled cleanup. The relations of the members are moved above that border in batches, so the
		// answer of the chat is the one that holds from the first hit after the cleanup - the per-reader
		// border below only catches the members the walk has already reached.
		if (Chat::getInstance($parentChatId)->isMessageBehindHistoryBorder($postId))
		{
			return new Result();
		}

		$subscriberIds = \CPullWatch::getUsersByTag('IM_PUBLIC_COMMENT_' . $parentChatId);
		$recipientIds = $this->filterByAccessToPost($subscriberIds, $parentChatId, $postId);

		// the exclusions the tag would have applied on its own
		$skippedUserIds = is_array($pull['skip_users'] ?? null) ? $pull['skip_users'] : [];

		return $this->sendToTagSubscribers(array_diff($recipientIds, $skippedUserIds), $pull);
	}

	/**
	 * The other border of the root post, and the reason it is weighed once and not per recipient: the
	 * boundary of the history is the tariff of the portal and not a property of a reader, so a post past
	 * it closes the discussion for everybody at once and the event has nobody to go to.
	 *
	 * The same weighing the list of discussions makes: Limit::hasAccessByDate() over the post, which
	 * resolves the chat the post belongs to and keeps the exception the channel family has. While the
	 * limit is off no table is read - getLimitDays() answers without reaching for one.
	 *
	 * One event asks it twice - send() ahead of both channels, sendToPostCommentSubscribers() for the
	 * legacy callers that reach it directly - and the answer is a property of the post and of the tariff
	 * of the portal, not of the channel or of the reader, so it is kept for the life of the sender.
	 */
	protected function isPostWithinHistoryLimit(int $postId): bool
	{
		return $this->postsWithinHistoryLimit[$postId] ??= $this->weighPostAgainstHistoryLimit($postId);
	}

	private function weighPostAgainstHistoryLimit(int $postId): bool
	{
		$limit = Limit::getInstance();

		if (!$limit->hasRestrictions())
		{
			return true;
		}

		$post = new Message($postId);

		return $limit->hasAccessByDate($post, $post->getDateCreate() ?? new DateTime());
	}

	/**
	 * The border is read from the relations of the parent and never from the ones the process happens to
	 * hold: the START_ID cache of the collection is refilled by every load of a relation, a stale object
	 * of a border that has just moved included ({@see RelationCollection::offsetSet()}), and a border read
	 * one hit too late would publish a reply into a discussion the reader may no longer open.
	 *
	 * One range read of IX_IM_RELATION_CHAT_START_ID (CHAT_ID, START_ID, USER_ID), entered at the post and
	 * left at the end of the chat: only the relations above the post are visited - never the whole
	 * membership - and the covering index carries the USER_ID the answer is made of, so no row is read.
	 * The readers found there are taken off the subscribers of the tag as the rows are streamed, so the
	 * memory of the answer is the list of subscribers this publication already holds. A subscriber with no
	 * relation has no border at all, exactly as {@see Message::checkAccess()} reads it.
	 *
	 * @param int[] $subscriberIds
	 * @return int[] the subscribers the root post of the discussion is readable for
	 */
	protected function filterByAccessToPost(array $subscriberIds, int $parentChatId, int $postId): array
	{
		if (empty($subscriberIds))
		{
			return [];
		}

		$recipientIds = array_fill_keys(array_map('intval', $subscriberIds), true);

		$readersBehindBorder = RelationTable::query()
			->setSelect(['USER_ID'])
			->where('CHAT_ID', $parentChatId)
			->where('START_ID', '>', $postId)
			->exec()
		;

		while ($reader = $readersBehindBorder->fetch())
		{
			unset($recipientIds[(int)$reader['USER_ID']]);
		}

		return array_keys($recipientIds);
	}

	/**
	 * The second half of what CPullWatch::AddToStack() does, with the list of subscribers already
	 * resolved and narrowed: the tag holds no state of its own past the resolve, and the delivery it
	 * ends in is this very Pull\Event::add(). A seam of its own and not sendPull(), which carries the
	 * per-user groups of the same event: a reader is cut out of one of them and not of the other.
	 *
	 * @param int[] $recipientIds
	 */
	protected function sendToTagSubscribers(array $recipientIds, array $pull): Result
	{
		if (empty($recipientIds))
		{
			return new Result();
		}

		return self::getPullEventResult(\Bitrix\Pull\Event::add(array_values($recipientIds), $pull));
	}

	protected function shouldSendMobilePush(?Chat $chat, Event $event): bool
	{
		return
			$event->shouldSendMobilePush()
			&& $chat !== null
			&& !($chat instanceof Chat\CommentChat)
		;
	}

	protected function sendGlobal(Event $event): Result
	{
		return self::getPullEventResult(\CPullStack::AddShared($event->getPullForPublic()));
	}

	protected function sendByTag(string $tag, array $pull): Result
	{
		return self::getPullEventResult(\CPullWatch::AddToStack($tag, $pull));
	}

	protected function sendSharedPull(array $pull): Result
	{
		return self::getPullEventResult(Chat\OpenChannelChat::sendSharedPull($pull));
	}

	protected function sendPull(array $recipients, array $pull): Result
	{
		return self::getPullEventResult(\Bitrix\Pull\Event::add($recipients, $pull));
	}

	protected function sendPush(array $recipients, array $push): Result
	{
		return self::getPullEventResult(\Bitrix\Pull\Push::add($recipients, $push));
	}

	protected static function getPullEventResult(bool $isSuccess): Result
	{
		if ($isSuccess)
		{
			return new Result();
		}

		$error = \Bitrix\Pull\Event::getLastError();
		if ($error instanceof Error)
		{
			return (new Result())->addError(new \Bitrix\Main\Error($error->msg, $error->code, $error->params));
		}

		return new Result();
	}

	protected function sendImmediately(): Result
	{
		$result = new Result();

		try
		{
			\Bitrix\Pull\Event::send();
		}
		catch (\Exception $exception)
		{
			$result->addError(new \Bitrix\Main\Error($exception->getMessage(), $exception->getCode()));
		}

		return $result;
	}
}
