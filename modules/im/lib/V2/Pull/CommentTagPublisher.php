<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Pull;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use CPullWatch;

/**
 * Publishes the traffic of a comment chat to the one tag every reader of the parent channel watches.
 *
 * The tag belongs to the channel, not to the post: a comment chat has no readership of its own, and
 * IM_PUBLIC_COMMENT_{channelId} is the only delivery its messages ever get. Clearing the channel history
 * for oneself hides the posts of that one reader and leaves the tag where it is - it is shared, and taking
 * it away would cost them the comments of the posts they still see. So the delivery is filtered instead:
 * those the post is already hidden from are taken off it through the skip list CPullWatch honours anyway.
 *
 * Whether there is anybody to take off is answered by the channel itself before a single relation is read:
 * a cleanup for oneself leaves the highest of the borders it has drawn on the chat
 * ({@see Chat::getSelfHistoryStartId()}), and a post that border has not passed is hidden from nobody,
 * whatever the size of the readership. Only a post somebody has really cleared past pays for the lookup,
 * and it pays for it once: who the post is hidden from is a property of the post, not of the push, so
 * the answer is read from the relations of the channel as a whole - never from the watchers of the tag,
 * whose number is what every push of a busy channel already costs - and kept until a border of the
 * channel moves. The pushes of a comment that follow it, in this process or in any other, cost the two
 * cached parameters of the chat and a read of that answer.
 *
 * The answers of a channel live under a version of their own, and a border moving in the channel does
 * not delete them - it replaces the version. An answer is written under the version that was current
 * when its relations were read, so an answer read before a cleanup and written after it lands under a
 * version nobody reads any more, however the two processes interleave; deleting the answers instead
 * would leave that late write standing until the lifetime runs out, and a reader who had just hidden a
 * post would be pushed its comments for an hour.
 *
 * The answer is bounded rather than trusted to stay small: it is held by every process pushing a comment
 * of the post and by the storage, and a post hidden from more readers than the answer may hold publishes
 * nothing on the tag at all. Of the two ways to be wrong, a comment kept back reaches everyone by the next
 * load of the post, while a comment let through reaches readers the post is hidden from.
 */
final class CommentTagPublisher
{
	/**
	 * Readers a post may be hidden from before the answer is too large to hold. Nothing in the product
	 * draws that many borders past one post - a border is drawn by hand, one reader at a time - so the
	 * bound is a guard on the memory, not a size the answers reach; the option overrides it.
	 */
	private const HIDDEN_READERS_LIMIT = 10000;

	/** What the storage keeps for a post hidden from more readers than the answer may hold. */
	private const HIDDEN_FROM_TOO_MANY = -1;

	/**
	 * Seconds the answer for a post outlives the cleanup that made it. A cleanup for oneself drops the
	 * answers of its channel itself; the lifetime covers what nothing announces - a reader who left the
	 * channel, or one who came back to it with a history of their own.
	 */
	private const CACHE_TTL = 3600;

	/**
	 * Seconds the version of the answers of a channel lives. It only ever changes with a cleanup for
	 * oneself, and a version that ran out costs the pushes one more read of the relations, never a
	 * delivery: the answers under a version nobody holds are never read again.
	 */
	private const VERSION_TTL = 2592000;

	private const VERSION_CACHE_ID = 'version';

	/** @var array<string, int[]> Readers a post is hidden from, per post, for the process. */
	private static array $hiddenReaders = [];

	/** @var array<int, string> The version the answers of a channel are read under, for the process. */
	private static array $versions = [];

	public static function tag(int $parentChatId): string
	{
		return 'IM_PUBLIC_COMMENT_' . $parentChatId;
	}

	public static function addToStack(Chat|int $commentChat, array $pull): bool
	{
		if (!Loader::includeModule('pull'))
		{
			return false;
		}

		$chat = $commentChat instanceof Chat ? $commentChat : Chat::getInstance($commentChat);
		$parentChatId = (int)$chat->getParentChatId();

		if ($parentChatId <= 0)
		{
			return false;
		}

		$tag = self::tag($parentChatId);

		if (!$chat instanceof Chat\CommentChat)
		{
			return CPullWatch::AddToStack($tag, $pull);
		}

		$parentMessageId = $chat->getParentMessageId();
		$channel = $chat->getParentChat();

		// The post is behind the border of the channel itself, so it is unreachable for everyone and the
		// comment chat is on its way out with the scheduled cleanup. Nobody on the tag may have this.
		if ($channel->isMessageBehindHistoryBorder($parentMessageId))
		{
			return true;
		}

		// Nobody has cleared the channel for themselves past this post, so there is no reader to take off
		// the tag and no border to read - two cached parameters of the chat answer for the whole readership.
		if ($channel->getSelfHistoryStartId() <= $parentMessageId)
		{
			return CPullWatch::AddToStack($tag, $pull);
		}

		$skippedUserIds = self::getReadersBehindBorder($parentChatId, $parentMessageId);

		// Hidden from more readers than the answer may hold: nothing goes out on the tag, rather than
		// something to the readers the post is hidden from.
		if ($skippedUserIds === null)
		{
			return true;
		}

		if ($skippedUserIds !== [])
		{
			$pull['skip_users'] = array_values(
				array_unique(array_merge($pull['skip_users'] ?? [], $skippedUserIds))
			);
		}

		return CPullWatch::AddToStack($tag, $pull);
	}

	/**
	 * A border moved in the channel makes the answers remembered for its posts stale - the ones of this
	 * process and the ones every other process reads. The latter are not deleted but left behind: the
	 * channel gets a new version, and from now on every process reads and writes under it. What was
	 * written under the old one, including by a process that read the relations before this border
	 * moved and writes its answer after it, is out of reach at once. Dropping the old answers from the
	 * storage is a rename of their directory, or one key of the key-value engine, either way finished
	 * by the deferred cleanup of the cache and not by this hit.
	 */
	public static function cleanCache(int $parentChatId): void
	{
		self::$hiddenReaders = [];
		unset(self::$versions[$parentChatId]);

		$staleVersion = self::readVersion($parentChatId);
		self::writeVersion($parentChatId, self::newVersion());

		if ($staleVersion !== null)
		{
			Application::getInstance()->getCache()->cleanDir(self::getAnswersDir($parentChatId, $staleVersion));
		}
	}

	/**
	 * The readers of the channel whose history starts above the post: those who cleared the channel past
	 * it, and those who joined after it and so have never seen it. A reader without a relation is a
	 * reader of an open channel, who is answered by the border of the chat above and never by this one.
	 *
	 * @return int[]|null null for a post hidden from more readers than the answer may hold
	 */
	private static function getReadersBehindBorder(int $parentChatId, int $parentMessageId): ?array
	{
		$postKey = $parentChatId . ':' . $parentMessageId;

		if (array_key_exists($postKey, self::$hiddenReaders))
		{
			return self::$hiddenReaders[$postKey];
		}

		// The version is taken before the relations are read: an answer belongs to the borders that
		// stood when it was read, and a border moved meanwhile replaces the version it is written under.
		$version = self::getVersion($parentChatId);
		$cache = Application::getInstance()->getCache();
		$cacheId = 'post_' . $parentMessageId;
		$cacheDir = self::getAnswersDir($parentChatId, $version);

		if ($cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			$answer = $cache->getVars();
			$userIds = $answer === self::HIDDEN_FROM_TOO_MANY ? null : (is_array($answer) ? $answer : []);
		}
		else
		{
			$userIds = self::selectReadersBehindBorder($parentChatId, $parentMessageId);
			self::storeAnswer($parentChatId, $parentMessageId, $version, $userIds ?? self::HIDDEN_FROM_TOO_MANY);
		}

		self::$hiddenReaders[$postKey] = $userIds;

		return $userIds;
	}

	/**
	 * @param int[]|int $answer the readers, or {@see self::HIDDEN_FROM_TOO_MANY}
	 */
	private static function storeAnswer(int $parentChatId, int $parentMessageId, string $version, array|int $answer): void
	{
		$cache = Application::getInstance()->getCache();

		if ($cache->startDataCache(self::CACHE_TTL, 'post_' . $parentMessageId, self::getAnswersDir($parentChatId, $version)))
		{
			$cache->endDataCache($answer);
		}
	}

	/**
	 * One range read of IX_IM_RELATION_CHAT_START_ID (CHAT_ID, START_ID, USER_ID), paid once per post
	 * and per border moved in the channel rather than once per push. The index is entered at the post
	 * and left at the end of the channel, so only the relations above the post are visited - never the
	 * whole membership - and it carries the USER_ID the answer is made of, so no row is read. The rows
	 * are streamed, and only the ids of the readers the post is hidden from are kept - up to the limit:
	 * one row past it is asked for, and reaching it is the whole answer.
	 *
	 * @return int[]|null null for a post hidden from more readers than the answer may hold
	 */
	private static function selectReadersBehindBorder(int $parentChatId, int $parentMessageId): ?array
	{
		$limit = self::readHiddenReadersLimit();
		$rows = RelationTable::query()
			->setSelect(['USER_ID'])
			->where('CHAT_ID', $parentChatId)
			->where('START_ID', '>', $parentMessageId)
			->setLimit($limit + 1)
			->exec()
		;

		$userIds = [];
		while ($row = $rows->fetch())
		{
			if (count($userIds) >= $limit)
			{
				return null;
			}

			$userIds[] = (int)$row['USER_ID'];
		}

		return $userIds;
	}

	private static function readHiddenReadersLimit(): int
	{
		return max(1, (int)Option::get('im', 'comment_hidden_readers_limit', self::HIDDEN_READERS_LIMIT));
	}

	/**
	 * The version the answers of the channel are read under, from the process, then from the storage. A
	 * channel without one gets one here, and it is kept by the storage for every other process; a version
	 * the storage lost is the same case, and the answers under the lost one are simply never read again.
	 */
	private static function getVersion(int $parentChatId): string
	{
		if (isset(self::$versions[$parentChatId]))
		{
			return self::$versions[$parentChatId];
		}

		$version = self::readVersion($parentChatId);

		if ($version === null)
		{
			$version = self::newVersion();
			self::writeVersion($parentChatId, $version);
		}

		self::$versions[$parentChatId] = $version;

		return $version;
	}

	private static function readVersion(int $parentChatId): ?string
	{
		$cache = Application::getInstance()->getCache();

		if (!$cache->initCache(self::VERSION_TTL, self::VERSION_CACHE_ID, self::getCacheDir($parentChatId)))
		{
			return null;
		}

		$version = $cache->getVars();

		return is_string($version) && $version !== '' ? $version : null;
	}

	private static function writeVersion(int $parentChatId, string $version): void
	{
		$cache = Application::getInstance()->getCache();
		$cacheDir = self::getCacheDir($parentChatId);

		$cache->clean(self::VERSION_CACHE_ID, $cacheDir);

		if ($cache->startDataCache(self::VERSION_TTL, self::VERSION_CACHE_ID, $cacheDir))
		{
			$cache->endDataCache($version);
		}
	}

	/**
	 * Unique rather than sequential: two processes filling in a version the storage lost must not agree
	 * on one by accident, or an answer written under it by one of them could be read by the other.
	 */
	private static function newVersion(): string
	{
		return Random::getString(16, true);
	}

	private static function getCacheDir(int $parentChatId): string
	{
		return '/bx/imc/comment_hidden_readers/' . ($parentChatId % 100) . '/' . $parentChatId;
	}

	private static function getAnswersDir(int $parentChatId, string $version): string
	{
		return self::getCacheDir($parentChatId) . '/' . $version;
	}
}
