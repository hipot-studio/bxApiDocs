<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Access\ParentChainFilterFactory;
use Bitrix\Im\V2\Integration\Search\Access\CodeCarrierResolver;
use Bitrix\Im\V2\Integration\Search\Access\OpenCodeReachResolver;
use Bitrix\Main\ORM\Query\Query;

/**
 * Bakes the access codes of an index entry. One builder for the whole domain: the online pipeline,
 * the permission updates of the chat lifecycle and the one-off steppers all address rights with the
 * very same vocabulary of codes.
 *
 * A relation of the chat is not the whole of a name either. Chat::checkAccess() hands the answer over
 * the whole chain of ancestors a type needs membership in, and the tree of the module keeps the
 * relations of a chat moved elsewhere untouched - the messenger drops such a member from the lists on
 * the read path instead. Every membership the builder reads therefore goes through that chain, so a
 * baked name says what the messenger says and never more.
 */
class PermissionBuilder
{
	/** Access code of the open contexts, granted to a user by the OnSearchCheckPermissions handler. */
	public const OPEN_ACCESS_CODE = 'IMOPEN';

	private const USER_CODE_PREFIX = 'U';

	/** Identifiers one read of the membership table carries, so that a large portion stays a few queries. */
	private const READ_CHUNK = 500;

	/**
	 * Pairs of an ancestor and a member one read of the chain carries. What the chain costs is counted
	 * in pairs and not in identifiers: a read naming every ancestor of a piece beside every member of it
	 * answers with their product, while the pairs a portion needs are its own membership, once per level
	 * of the tree above it.
	 */
	private const PAIR_CHUNK = 5000;

	/** @var array<int, array<int, int>> membership of a chat as userId => startId */
	private array $membershipByChatId = [];

	/** @var array<int, array<int, int>> non-carriers the chain of a chat lets through as userId => startId */
	private array $allowedNonCarriersByChatId = [];

	/** @var array<int, int[]> ancestors a member of a chat has to belong to as well */
	private array $ancestorsByChatId = [];

	public function __construct(
		private readonly CodeCarrierResolver $codeCarrierResolver,
		private readonly OpenCodeReachResolver $openCodeReachResolver,
		private readonly ParentChainFilterFactory $parentChainFilterFactory,
	)
	{}

	/**
	 * @param int $accessKeyId Message the entry becomes reachable from: the message itself, or the post
	 *                         of a comment, which the builder resolves on its own.
	 * @return string[]
	 */
	public function build(Chat $chat, int $accessKeyId): array
	{
		if ($chat instanceof Chat\CommentChat)
		{
			return $this->build($chat->getParentChat(), $chat->getParentMessageId());
		}

		// the open type alone does not hand out the shared code: a chat answered by a parent it needs
		// membership in is reachable exactly as that parent is, and the code names the whole portal
		if ($this->openCodeReachResolver->isReached($chat))
		{
			return $this->buildForOpenChat($chat, $accessKeyId);
		}

		return $this->buildForMemberChat($chat, $accessKeyId);
	}

	public static function buildUserCode(int $userId): string
	{
		return self::USER_CODE_PREFIX . $userId;
	}

	/**
	 * The member a baked code names, for the callers that hold a code alone and have to answer whether
	 * the membership behind it still stands.
	 *
	 * @return int|null Null for every code that names no member, the shared code of the open contexts
	 *                  included.
	 */
	public static function parseUserCode(string $code): ?int
	{
		if (preg_match('/^' . self::USER_CODE_PREFIX . '([1-9]\d*)$/', $code, $matches) !== 1)
		{
			return null;
		}

		return (int)$matches[1];
	}

	/**
	 * Membership of several chats for a walk about to bake the codes of a whole portion of messages. A
	 * portion of five hundred messages of as many closed chats costs a select per chat otherwise - the
	 * chats of the portion are warmed as objects, and nothing of their membership comes with them.
	 *
	 * A chat the query brings no row for is remembered as one without members: an empty answer is an
	 * answer, and reading it once per message is the very cost this exists to remove. A chat already
	 * remembered is left as it is - what stands here was read for the portion that runs.
	 *
	 * The chain a chat needs membership in is answered here and not by the query: asked in SQL, that
	 * chain is a join, chats of different chains may not share a query, and a portion collected from as
	 * many projects falls back to a select apiece. So the members of the chats are read first, and the
	 * chain is then applied by intersecting - the very predicate the join carries, at a cost the shape
	 * of the tree no longer moves.
	 *
	 * What an ancestor is asked about is only ever the people of the chats it stands above: a member of
	 * the ancestor who belongs to no chat of the portion cannot change any answer, and reading the whole
	 * membership of a project to drop all of it is what turns a walk over small chats of large projects
	 * into a memory_limit. Neither may the ancestors of a piece be named beside the people of all of it
	 * at once - that read answers with their product, which is the same memory_limit reached from the
	 * other side. So a read carries the pairs the chain needs and no more, in pieces bounded by their
	 * number, and every piece is applied and released before the next one is read.
	 *
	 * @param int[] $chatIds
	 */
	public function rememberMembershipOf(array $chatIds): void
	{
		$chatIds = array_values(array_diff(
			array_unique(array_filter(array_map('intval', $chatIds), static fn (int $chatId): bool => $chatId > 0)),
			array_keys($this->membershipByChatId),
		));
		if ($chatIds === [])
		{
			return;
		}

		$chatIdsByAncestor = [];
		foreach ($chatIds as $chatId)
		{
			$chain = $this->ancestorsByChatId[$chatId]
				??= $this->parentChainFilterFactory->requiredAncestorsOf($chatId)
			;

			foreach ($chain as $ancestorId)
			{
				$chatIdsByAncestor[$ancestorId][] = $chatId;
			}
		}

		$membership = array_fill_keys($chatIds, []);
		foreach ($this->readBoundariesOf($chatIds) as $chatId => $boundaries)
		{
			$membership[$chatId] = $boundaries;
		}

		$this->keepMembersTheChainsAllow($membership, $chatIdsByAncestor);

		$this->membershipByChatId += $membership;
	}

	/**
	 * Of the members read for the chats of the portion, the ones every ancestor of their chat holds as
	 * well. An ancestor is asked about the members of the chats it stands above and about nobody else,
	 * the pairs of a read are bounded by their number, and every piece is applied and released before
	 * the next one is read - so what the method holds at once stays a piece of the membership of the
	 * portion itself.
	 *
	 * @param array<int, array<int, int>> $membership chatId => userId => startId
	 * @param array<int, int[]> $chatIdsByAncestor ancestorId => chats it stands above
	 */
	private function keepMembersTheChainsAllow(array &$membership, array $chatIdsByAncestor): void
	{
		$piece = [];
		$pairs = 0;

		foreach ($chatIdsByAncestor as $ancestorId => $chatIdsOfAncestor)
		{
			$userIds = [];
			foreach ($chatIdsOfAncestor as $chatId)
			{
				foreach (array_keys($membership[$chatId]) as $userId)
				{
					$userIds[$userId] = true;
				}
			}

			foreach (array_chunk(array_keys($userIds), self::PAIR_CHUNK) as $namedPiece)
			{
				if (
					$piece !== []
					&& ($pairs + count($namedPiece) > self::PAIR_CHUNK || count($piece) >= self::READ_CHUNK)
				)
				{
					$this->keepWhatThePieceAllows($membership, $piece, $chatIdsByAncestor);
					$piece = [];
					$pairs = 0;
				}

				$piece[$ancestorId] = $namedPiece;
				$pairs += count($namedPiece);
			}
		}

		if ($piece !== [])
		{
			$this->keepWhatThePieceAllows($membership, $piece, $chatIdsByAncestor);
		}
	}

	/**
	 * The answer of one piece, applied and gone. A member is dropped by it and never kept by it: the
	 * pairs of one ancestor may fall into pieces of their own, and a member the piece that runs has not
	 * asked about is answered for by another one.
	 *
	 * @param array<int, array<int, int>> $membership chatId => userId => startId
	 * @param array<int, int[]> $piece ancestorId => members it is asked about
	 * @param array<int, int[]> $chatIdsByAncestor ancestorId => chats it stands above
	 */
	private function keepWhatThePieceAllows(array &$membership, array $piece, array $chatIdsByAncestor): void
	{
		$membersOfAncestors = $this->readMembersOf($piece);

		foreach ($piece as $ancestorId => $askedUserIds)
		{
			$denied = array_diff_key(
				array_fill_keys($askedUserIds, true),
				$membersOfAncestors[$ancestorId] ?? [],
			);
			if ($denied === [])
			{
				continue;
			}

			foreach ($chatIdsByAncestor[$ancestorId] as $chatId)
			{
				$membership[$chatId] = array_diff_key($membership[$chatId], $denied);
			}
		}
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, array<int, int>> chatId => userId => startId
	 */
	private function readBoundariesOf(array $chatIds): array
	{
		$boundaries = [];

		foreach (array_chunk($chatIds, self::READ_CHUNK) as $chunk)
		{
			$rows = RelationTable::query()
				->setSelect(['CHAT_ID', 'USER_ID', 'START_ID'])
				->whereIn('CHAT_ID', $chunk)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$boundaries[(int)$row['CHAT_ID']][(int)$row['USER_ID']] = (int)$row['START_ID'];
			}
		}

		return $boundaries;
	}

	/**
	 * Which of the named people the chats hold, as a set: the boundary of an ancestor says nothing about
	 * the entry being baked, and the chain asks about belonging alone. Every chat stands in the read
	 * beside the names of its own - an unrestricted read would bring the whole membership of a project
	 * to answer about the handful of members its small child chat has, and one list of names shared by
	 * the chats of the read would bring every pairing of a project with somebody else's member. The rows
	 * are taken one by one for the same reason: what the read answers with is never held twice.
	 *
	 * @param array<int, int[]> $userIdsByChatId chatId => members to ask about
	 * @return array<int, array<int, true>> chatId => userId => true
	 */
	private function readMembersOf(array $userIdsByChatId): array
	{
		$asked = Query::filter()->logic('or');
		$pairs = 0;

		foreach ($userIdsByChatId as $chatId => $userIds)
		{
			if ($userIds === [])
			{
				continue;
			}

			$asked->where(Query::filter()->where('CHAT_ID', $chatId)->whereIn('USER_ID', $userIds));
			$pairs += count($userIds);
		}

		if ($pairs === 0)
		{
			return [];
		}

		$rows = RelationTable::query()
			->setSelect(['CHAT_ID', 'USER_ID'])
			->where($asked)
			->exec()
		;

		$members = [];
		while ($row = $rows->fetch())
		{
			$members[(int)$row['CHAT_ID']][(int)$row['USER_ID']] = true;
		}

		return $members;
	}

	/**
	 * Drops every remembered answer about the membership of a chat: its whole membership, the
	 * non-carriers among them and the ones of those the chain above the chat lets through. Called by the
	 * facade from every point that moves a member or a history boundary of one: the codes of an entry
	 * written afterwards would otherwise describe the chat as it was before the change, and a code left
	 * over is an overshow.
	 */
	public function forgetChat(int $chatId): void
	{
		unset(
			$this->membershipByChatId[$chatId],
			$this->allowedNonCarriersByChatId[$chatId],
			$this->ancestorsByChatId[$chatId],
		);
		$this->codeCarrierResolver->forgetChat($chatId);
		$this->openCodeReachResolver->forgetChat($chatId);
	}

	/**
	 * Drops everything remembered so far, for the walks that read the archive by portions. A walk runs
	 * in a process of its own, where no point of the facade reports a membership that moved, so the
	 * portion is the unit of work an answer about it may live within.
	 */
	public function forgetAllChats(): void
	{
		$this->membershipByChatId = [];
		$this->allowedNonCarriersByChatId = [];
		$this->ancestorsByChatId = [];
		$this->codeCarrierResolver->forgetAllChats();
		$this->openCodeReachResolver->forgetAllChats();
	}

	/**
	 * History boundaries of the named members, read the very same way the codes of an entry are read.
	 * A member without a relation is left out: Open Lines add participants for whom no relation row is
	 * created at all, and rights may not be granted to those. So is a member the required ancestors of
	 * the chat deny - the one answer for handing a name out, handing it back and taking it off.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> userId => startId
	 */
	public function getHistoryBoundaries(Chat $chat, array $userIds): array
	{
		$userIds = array_filter(array_map('intval', $userIds), static fn (int $userId): bool => $userId > 0);
		if ($userIds === [])
		{
			return [];
		}

		return $this->getRawRelations($chat, array_values(array_unique($userIds)));
	}

	/**
	 * An open context is reachable by the shared code, so only the members that code leaves out are
	 * listed by name. Baking the whole membership would bring back the growth 'messages x members'
	 * the shared code exists to remove.
	 *
	 * @return string[]
	 */
	private function buildForOpenChat(Chat $chat, int $accessKeyId): array
	{
		$codes = [self::OPEN_ACCESS_CODE];

		foreach ($this->allowedNonCarriersOf($chat) as $userId => $startId)
		{
			if ($this->isReachableFrom($startId, $accessKeyId))
			{
				$codes[] = self::buildUserCode($userId);
			}
		}

		return $codes;
	}

	/**
	 * @return string[]
	 */
	private function buildForMemberChat(Chat $chat, int $accessKeyId): array
	{
		$codes = [];

		foreach ($this->getMembership($chat) as $userId => $startId)
		{
			if ($this->isReachableFrom($startId, $accessKeyId))
			{
				$codes[] = self::buildUserCode($userId);
			}
		}

		return $codes;
	}

	/**
	 * The members named beside the shared code, remembered until the chat is forgotten, exactly as its
	 * whole membership is. The resolver remembers the non-carriers it picked, the chain the ancestors
	 * deny is read past it, and a walk asked that of the very same chat once per message otherwise. The
	 * boundary of a member is not part of the answer: it is read against the entry being baked and
	 * belongs to the message and not to the chat.
	 *
	 * @return array<int, int> userId => startId
	 */
	private function allowedNonCarriersOf(Chat $chat): array
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0)
		{
			return [];
		}

		return $this->allowedNonCarriersByChatId[$chatId]
			??= $this->keepAllowedByAncestors($chat, $this->codeCarrierResolver->nonCarriersOf($chat))
		;
	}

	/**
	 * Of the members named beside the shared code, the ones the required ancestors of the chat let
	 * through. The resolver of the carriers answers about the chat alone, while an open context under a
	 * parent it needs membership in is reachable exactly as that parent is: an ancestor open to the
	 * whole portal is still closed to the extranet user, the guest and the collaber, and those are
	 * precisely the members a name is baked for here.
	 *
	 * @param array<int, int> $members userId => startId
	 * @return array<int, int> userId => startId
	 */
	private function keepAllowedByAncestors(Chat $chat, array $members): array
	{
		if ($members === [] || $this->requiredAncestorsOf($chat) === [])
		{
			return $members;
		}

		return array_intersect_key($members, $this->getRawRelations($chat, array_keys($members)));
	}

	/**
	 * The whole membership of a chat, remembered until the chat is forgotten, exactly as the
	 * non-carriers of an open context are: the chats of a portion repeat, and a walk reads the same
	 * membership once per message otherwise.
	 *
	 * @return array<int, int> userId => startId
	 */
	private function getMembership(Chat $chat): array
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0)
		{
			return [];
		}

		return $this->membershipByChatId[$chatId] ??= $this->getRawRelations($chat);
	}

	/**
	 * START_ID comes straight from the table on purpose: the private chat overrides its getter with a
	 * hardcoded zero, and baked rights would then disagree with the actual history boundary.
	 *
	 * @param int[] $userIds Members to read; the whole membership when empty.
	 * @return array<int, int> userId => startId
	 */
	private function getRawRelations(Chat $chat, array $userIds = []): array
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0)
		{
			return [];
		}

		$query = RelationTable::query()
			->setSelect(['USER_ID', 'START_ID'])
			->where('CHAT_ID', $chatId)
		;

		if ($userIds !== [])
		{
			$query->whereIn('USER_ID', $userIds);
		}

		$this->parentChainFilterFactory->forAncestors($this->requiredAncestorsOf($chat))->apply($query);

		$rows = $query->fetchAll();

		$relations = [];
		foreach ($rows as $row)
		{
			$relations[(int)$row['USER_ID']] = (int)$row['START_ID'];
		}

		return $relations;
	}

	/**
	 * The chain of a chat, remembered for as long as its membership is: a portion holds hundreds of
	 * messages of the same few chats, and the walk up the tree costs a read per level.
	 *
	 * @return int[]
	 */
	private function requiredAncestorsOf(Chat $chat): array
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0)
		{
			return [];
		}

		return $this->ancestorsByChatId[$chatId]
			??= $this->parentChainFilterFactory->requiredAncestorsOfChat($chat)
		;
	}

	private function isReachableFrom(int $startId, int $accessKeyId): bool
	{
		return $startId === 0 || $startId <= $accessKeyId;
	}
}
