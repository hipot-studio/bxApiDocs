<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Enrichment;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatClassResolver;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserFactory;
use Bitrix\Im\V2\Integration\Search\Access\VisibilityFilter;
use Bitrix\Im\V2\Integration\Search\Result\MessageSearchItem;
use Bitrix\Im\V2\Integration\Search\SearchRecord;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

/**
 * Enriches a portion of raw entries and re-checks the access of the user granularly (ALG-04).
 *
 * Everything is read in batches by lists of identifiers; a query per record is not allowed here. The
 * re-check runs per unique access chat and never per row: access plus the personal history boundary
 * of the user, which for a comment is asked of the parent channel and the key of its post.
 *
 * MessageCollection is deliberately not used: it carries the meaning of a single chat, and on a
 * portion spanning many chats its common chat degrades to NullChat while its access check is a loop.
 */
class PortionEnricher
{
	private const
		DELETED_PARAM = 'IS_DELETED',
		DELETED_PARAM_VALUE = 'Y'
	;

	public function __construct(
		private readonly VisibilityFilter $visibilityFilter,
	)
	{}

	/**
	 * @param array<int, SearchRecord> $records Raw entries of the window, keyed by their raw position.
	 * @return MessageSearchItem[] Rows in the order of the raw output.
	 */
	public function enrichAndFilter(array $records, int $userId): array
	{
		if ($records === [] || $userId <= 0)
		{
			return [];
		}

		$messageIds = $this->collectUnique($records, static fn (SearchRecord $record): int => $record->messageId);
		$alive = $this->loadMessages($messageIds);
		if ($alive === [])
		{
			return [];
		}

		$chats = $this->loadChats($this->collectColumn($alive, 'CHAT_ID'));
		$commentChats = array_filter($chats, fn (array $chat): bool => $this->isComment($chat));
		$parents = $this->loadChats($this->collectColumn($commentChats, 'PARENT_ID'));
		$postIds = $this->collectColumn($commentChats, 'PARENT_MID');

		$knownChats = $chats + $parents;
		$peers = $this->loadPeers($this->getPrivateChatIds($chats), $userId);
		$posts = $this->loadAlivePosts($postIds);
		$deleted = $this->loadDeleted(array_merge($messageIds, $postIds));

		// warms up the cache the author names and the companions are then taken from one by one
		UserFactory::getInstance()->getUsersByIds(array_merge(
			$this->collectUnique($records, static fn (SearchRecord $record): int => $record->userId),
			array_values($peers),
		));

		$positions = $this->resolvePositions($records, $alive, $chats);
		$accessChatIds = $this->collectColumn($positions, 'accessChatId');
		$starts = $this->loadStartIds($accessChatIds, $userId);
		$visible = $this->resolveVisibleChats($accessChatIds, $knownChats, $starts, $peers, $userId);

		$items = [];
		foreach ($records as $rawPosition => $record)
		{
			$position = $positions[$rawPosition] ?? null;
			if ($position === null || !isset($visible[$position['accessChatId']]))
			{
				continue;
			}

			if (isset($deleted[$record->messageId]))
			{
				continue;
			}

			$chat = $knownChats[$position['chatId']];
			$parent = $this->isComment($chat) ? ($parents[(int)$chat['PARENT_ID']] ?? null) : null;

			// a thread is visible only while its post is alive
			if (
				$this->isComment($chat)
				&& (
					$parent === null
					|| !isset($posts[$position['boundaryKey']])
					|| isset($deleted[$position['boundaryKey']])
				)
			)
			{
				continue;
			}

			$peerId = $this->resolvePeerId($chat, $peers, $userId);
			if ($this->isPrivate($chat) && $peerId === null)
			{
				// the lag window of a user deletion: an undershow, never an overshow
				continue;
			}

			$startId = $starts[$position['accessChatId']] ?? 0;
			if ($startId > 0 && $position['boundaryKey'] < $startId)
			{
				continue;
			}

			$items[] = $this->buildItem($record, $rawPosition, $chat, $alive[$record->messageId], $parent, $peerId);
		}

		return $items;
	}

	/**
	 * Two derived identifiers per row: the chat the access is asked of, and the key of the personal
	 * history boundary. For a comment both come from the parent channel and the key of its post - the
	 * relation of a comment chat means a subscription to the thread and says nothing about visibility.
	 *
	 * @param array<int, SearchRecord> $records
	 * @param array<int, array> $alive
	 * @param array<int, array> $chats
	 * @return array<int, array{chatId: int, accessChatId: int, boundaryKey: int}>
	 */
	private function resolvePositions(array $records, array $alive, array $chats): array
	{
		$positions = [];

		foreach ($records as $rawPosition => $record)
		{
			$message = $alive[$record->messageId] ?? null;
			$chat = $message === null ? null : ($chats[(int)$message['CHAT_ID']] ?? null);
			if ($chat === null)
			{
				continue;
			}

			$isComment = $this->isComment($chat);
			$positions[$rawPosition] = [
				'chatId' => (int)$chat['ID'],
				'accessChatId' => $isComment ? (int)$chat['PARENT_ID'] : (int)$chat['ID'],
				'boundaryKey' => $isComment ? (int)$chat['PARENT_MID'] : $record->messageId,
			];
		}

		return $positions;
	}

	/**
	 * @param int[] $accessChatIds
	 * @param array<int, array> $knownChats
	 * @param array<int, int> $starts
	 * @param array<int, int> $peers
	 * @return array<int, true> Visible chats as a set.
	 */
	private function resolveVisibleChats(
		array $accessChatIds,
		array $knownChats,
		array $starts,
		array $peers,
		int $userId,
	): array
	{
		$queryable = [];
		$companionBased = [];
		$delegated = [];

		foreach ($accessChatIds as $chatId)
		{
			$chat = $knownChats[$chatId] ?? null;

			if ($chat !== null && $this->isCompanionBased($chat))
			{
				$companionBased[] = $chatId;
			}
			elseif ($chat !== null && !$this->isDelegated($chat))
			{
				$queryable[] = $chatId;
			}
			else
			{
				$delegated[] = $chatId;
			}
		}

		$visible = [];
		foreach ($this->visibilityFilter->filterVisible($queryable, $userId) as $chatId)
		{
			$visible[$chatId] = true;
		}

		$this->dropChatsBehindADeniedAncestor($visible, $knownChats, $userId);

		foreach ($companionBased as $chatId)
		{
			if ($this->isCompanionVisible($chatId, $starts, $peers, $userId))
			{
				$visible[$chatId] = true;
			}
		}

		// one check per unique access chat, answered by the owner of the delegated access; the chats of
		// them all are read by one query first, or every check of the portion would load its own
		ChatFactory::getInstance()->getChatsByIds($delegated);

		foreach ($delegated as $chatId)
		{
			if (Chat::getInstance($chatId)->checkAccess($userId)->isSuccess())
			{
				$visible[$chatId] = true;
			}
		}

		return $visible;
	}

	/**
	 * Delegated access of the ancestors, which the query of the first branch cannot ask about.
	 *
	 * Chat::checkAccess() of a child hands the whole answer over to the parent while the type of the
	 * child requires its membership, so a plain group chat of a project the tariff has closed is denied
	 * by the project and never by the chat itself. The filter of the parent chain states the relation
	 * and the openness of every ancestor as SQL, and neither expresses a handler of an external module:
	 * an open collab looks open to it exactly while its owner turns the user away.
	 *
	 * Asked once per ancestor and not per chat: the chats of one project share their project.
	 *
	 * @param array<int, true> $visible Chats the queryable branch let through, narrowed in place.
	 * @param array<int, array> $knownChats
	 */
	private function dropChatsBehindADeniedAncestor(array &$visible, array $knownChats, int $userId): void
	{
		$chains = $this->resolveDelegatedAncestors(array_keys($visible), $knownChats);
		if ($chains === [])
		{
			return;
		}

		$ancestorIds = array_values(array_unique(array_merge(...array_values($chains))));
		ChatFactory::getInstance()->getChatsByIds($ancestorIds);

		$granted = [];
		foreach ($ancestorIds as $ancestorId)
		{
			$granted[$ancestorId] = Chat::getInstance($ancestorId)->checkAccess($userId)->isSuccess();
		}

		foreach ($chains as $chatId => $ownAncestorIds)
		{
			foreach ($ownAncestorIds as $ancestorId)
			{
				if (!$granted[$ancestorId])
				{
					unset($visible[$chatId]);

					break;
				}
			}
		}
	}

	/**
	 * The chain of every given chat walked upwards exactly as Chat::checkAccess() walks it - while the
	 * type of the level requires the membership of its parent, and no deeper than the filter of the
	 * parent chain joins - keeping the ancestors whose access is delegated.
	 *
	 * The rows of the ancestors are read by one query per level: a portion spans many chats, and a
	 * chain read chat by chat would cost a query per row of the output.
	 *
	 * @param int[] $chatIds
	 * @param array<int, array> $knownChats
	 * @return array<int, int[]> chatId => identifiers of its delegated ancestors, chats without them absent
	 */
	private function resolveDelegatedAncestors(array $chatIds, array $knownChats): array
	{
		$rows = $knownChats;
		$chains = [];

		$pending = [];
		foreach ($chatIds as $chatId)
		{
			$pending[$chatId] = [$chatId];
		}

		for ($depth = 0; $depth < ChatAncestorNavigator::DEFAULT_DEPTH && $pending !== []; $depth++)
		{
			$parents = [];
			foreach ($pending as $chatId => $origins)
			{
				$row = $rows[$chatId] ?? null;
				$parentId = (int)($row['PARENT_ID'] ?? 0);
				if ($row === null || $parentId <= 0 || !$this->requiresParentMembership($row))
				{
					continue;
				}

				$parents[$parentId] = array_merge($parents[$parentId] ?? [], $origins);
			}

			$rows += $this->loadChats(array_values(array_diff(array_keys($parents), array_keys($rows))));

			$pending = [];
			foreach ($parents as $parentId => $origins)
			{
				$row = $rows[$parentId] ?? null;
				if ($row === null)
				{
					continue;
				}

				if ($this->isDelegated($row))
				{
					foreach ($origins as $origin)
					{
						$chains[$origin][] = $parentId;
					}
				}

				$pending[$parentId] = $origins;
			}
		}

		return $chains;
	}

	private function requiresParentMembership(array $chat): bool
	{
		return ServiceLocator::getInstance()->get(TypeRegistry::class)->requiresParentMembership(
			(string)$chat['TYPE'],
			(string)($chat['ENTITY_TYPE'] ?? ''),
		);
	}

	/**
	 * The answer of PrivateChat::checkAccessInternal() restated on what the portion has already read:
	 * the relation of the user, which the boundaries carry, and the companion, whom the visibility
	 * rules of the messenger still have to let through. Membership alone would be wider than the check
	 * - an extranet companion outside a shared group is a member the user may not reach.
	 *
	 * @param array<int, int> $starts
	 * @param array<int, int> $peers
	 */
	private function isCompanionVisible(int $chatId, array $starts, array $peers, int $userId): bool
	{
		if (!array_key_exists($chatId, $starts))
		{
			return false;
		}

		$peerId = $peers[$chatId] ?? null;

		return $peerId !== null && User::getInstance($peerId)->checkAccess($userId)->isSuccess();
	}

	private function buildItem(
		SearchRecord $record,
		int $rawPosition,
		array $chat,
		array $message,
		?array $parent,
		?int $peerId,
	): MessageSearchItem
	{
		$chatId = (int)$chat['ID'];
		$authorId = (int)$message['AUTHOR_ID'];

		return new MessageSearchItem(
			messageId: $record->messageId,
			chatId: $chatId,
			dialogId: $peerId === null ? 'chat' . $chatId : (string)$peerId,
			date: $this->formatDate($message['DATE_CREATE']),
			authorId: $authorId,
			authorName: (string)User::getInstance($authorId)->getName(),
			chatTitle: $this->resolveTitle($chat, $parent),
			chatType: \Bitrix\Im\Chat::getType([
				'ID' => $chatId,
				'TYPE' => (string)$chat['TYPE'],
				'ENTITY_TYPE' => (string)($chat['ENTITY_TYPE'] ?? ''),
			]),
			snippet: $record->snippet,
			rawPosition: $rawPosition,
			parentChatId: $parent === null ? null : (int)$parent['ID'],
			parentTitle: $parent === null ? null : (string)$parent['TITLE'],
			postId: $parent === null ? null : (int)$chat['PARENT_MID'],
		);
	}

	/**
	 * A private chat has no title of its own; a thread has none either, so it shows the title of the
	 * channel its post belongs to.
	 */
	private function resolveTitle(array $chat, ?array $parent): ?string
	{
		if ($this->isPrivate($chat))
		{
			return null;
		}

		$source = $parent ?? $chat;

		return isset($source['TITLE']) ? (string)$source['TITLE'] : null;
	}

	/**
	 * @param array<int, int> $peers
	 */
	private function resolvePeerId(array $chat, array $peers, int $userId): ?int
	{
		if (!$this->isPrivate($chat))
		{
			return null;
		}

		// 'Favorites' is a private chat whose only relation is its owner: the companion is the user
		if ((string)($chat['ENTITY_TYPE'] ?? '') === Chat::ENTITY_TYPE_FAVORITE)
		{
			return $userId;
		}

		return $peers[(int)$chat['ID']] ?? null;
	}

	private function formatDate(mixed $dateCreate): string
	{
		return $dateCreate instanceof DateTime ? $dateCreate->format('c') : (string)$dateCreate;
	}

	/**
	 * The branch of the re-check is chosen by the class of the chat and never by its type: a collab
	 * delegates its access to an external handler, and an ORM filter cannot express that. Openness of
	 * the type does not move an open collab out of the delegated branch.
	 */
	private function isDelegated(array $chat): bool
	{
		$class = $this->resolveChatClass($chat);

		return is_a($class, Chat\PrivateChat::class, true)
			|| is_a($class, Chat\ExternalChat::class, true)
			|| is_a($class, Chat\OpenLineChat::class, true)
		;
	}

	/**
	 * Private chats are the bulk of what a search returns, and their access is answerable in batch: the
	 * class decides it by the companion, which the portion reads anyway.
	 *
	 * 'Favorites' inherits the class but answers by the author of the chat, and a private chat with a
	 * parent would ask the parent chain, so both stay with the delegated branch.
	 */
	private function isCompanionBased(array $chat): bool
	{
		if ((int)($chat['PARENT_ID'] ?? 0) > 0)
		{
			return false;
		}

		$class = $this->resolveChatClass($chat);

		return is_a($class, Chat\PrivateChat::class, true)
			&& !is_a($class, Chat\FavoriteChat::class, true)
		;
	}

	private function isComment(array $chat): bool
	{
		return (string)$chat['TYPE'] === Chat::IM_TYPE_COMMENT;
	}

	private function isPrivate(array $chat): bool
	{
		return (string)$chat['TYPE'] === Chat::IM_TYPE_PRIVATE;
	}

	/**
	 * @return class-string<Chat>
	 */
	private function resolveChatClass(array $chat): string
	{
		return ServiceLocator::getInstance()->get(ChatClassResolver::class)->resolveForInit([
			'TYPE' => (string)$chat['TYPE'],
			'ENTITY_TYPE' => (string)($chat['ENTITY_TYPE'] ?? ''),
		]);
	}

	/**
	 * @param array<int, array> $chats
	 * @return int[]
	 */
	private function getPrivateChatIds(array $chats): array
	{
		$ids = [];
		foreach ($chats as $chat)
		{
			if ($this->isPrivate($chat))
			{
				$ids[] = (int)$chat['ID'];
			}
		}

		return $ids;
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, array> Live messages by id.
	 */
	private function loadMessages(array $messageIds): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = MessageTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE'])
			->whereIn('ID', $messageIds)
			->fetchAll()
		;

		return $this->indexById($rows);
	}

	/**
	 * @param int[] $postIds
	 * @return array<int, true> Posts that are still alive, as a set.
	 */
	private function loadAlivePosts(array $postIds): array
	{
		if ($postIds === [])
		{
			return [];
		}

		$rows = MessageTable::query()->setSelect(['ID'])->whereIn('ID', $postIds)->fetchAll();

		$alive = [];
		foreach ($rows as $row)
		{
			$alive[(int)$row['ID']] = true;
		}

		return $alive;
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, array> Chats by id.
	 */
	private function loadChats(array $chatIds): array
	{
		if ($chatIds === [])
		{
			return [];
		}

		$rows = ChatTable::query()
			->setSelect(['ID', 'TITLE', 'TYPE', 'ENTITY_TYPE', 'PARENT_ID', 'PARENT_MID'])
			->whereIn('ID', $chatIds)
			->fetchAll()
		;

		return $this->indexById($rows);
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, int> chatId => companion id
	 */
	private function loadPeers(array $chatIds, int $userId): array
	{
		if ($chatIds === [])
		{
			return [];
		}

		$rows = RelationTable::query()
			->setSelect(['CHAT_ID', 'USER_ID'])
			->whereIn('CHAT_ID', $chatIds)
			->whereNot('USER_ID', $userId)
			->fetchAll()
		;

		$peers = [];
		foreach ($rows as $row)
		{
			$peers[(int)$row['CHAT_ID']] = (int)$row['USER_ID'];
		}

		return $peers;
	}

	/**
	 * START_ID is read raw, with the very semantics the index bakes: the private chat overrides its
	 * getter with a hardcoded zero.
	 *
	 * @param int[] $chatIds
	 * @return array<int, int> chatId => startId
	 */
	private function loadStartIds(array $chatIds, int $userId): array
	{
		if ($chatIds === [])
		{
			return [];
		}

		$rows = RelationTable::query()
			->setSelect(['CHAT_ID', 'START_ID'])
			->whereIn('CHAT_ID', $chatIds)
			->where('USER_ID', $userId)
			->fetchAll()
		;

		$starts = [];
		foreach ($rows as $row)
		{
			$starts[(int)$row['CHAT_ID']] = (int)$row['START_ID'];
		}

		return $starts;
	}

	/**
	 * Read by the value and not by the presence of the row: the parameter is a boolean stored as
	 * 'Y' or 'N', and the hygiene registry, the second reader of the same rule, reads it the same way.
	 *
	 * @param int[] $messageIds
	 * @return array<int, true> Messages marked as deleted, as a set.
	 */
	private function loadDeleted(array $messageIds): array
	{
		$messageIds = array_values(array_unique($messageIds));
		if ($messageIds === [])
		{
			return [];
		}

		$rows = MessageParamTable::query()
			->setSelect(['MESSAGE_ID'])
			->whereIn('MESSAGE_ID', $messageIds)
			->where('PARAM_NAME', self::DELETED_PARAM)
			->where('PARAM_VALUE', self::DELETED_PARAM_VALUE)
			->fetchAll()
		;

		$deleted = [];
		foreach ($rows as $row)
		{
			$deleted[(int)$row['MESSAGE_ID']] = true;
		}

		return $deleted;
	}

	/**
	 * @param array<int, array> $rows
	 * @return array<int, array>
	 */
	private function indexById(array $rows): array
	{
		$indexed = [];
		foreach ($rows as $row)
		{
			$indexed[(int)$row['ID']] = $row;
		}

		return $indexed;
	}

	/**
	 * @param array<int, SearchRecord> $records
	 * @return int[]
	 */
	private function collectUnique(array $records, callable $getter): array
	{
		$values = [];
		foreach ($records as $record)
		{
			$value = $getter($record);
			if ($value > 0)
			{
				$values[$value] = $value;
			}
		}

		return array_values($values);
	}

	/**
	 * @param array<int, array> $rows
	 * @return int[]
	 */
	private function collectColumn(array $rows, string $column): array
	{
		$values = [];
		foreach ($rows as $row)
		{
			$value = (int)($row[$column] ?? 0);
			if ($value > 0)
			{
				$values[$value] = $value;
			}
		}

		return array_values($values);
	}
}
