<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Access;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;

/**
 * Whether the shared access code of the open contexts reaches a chat.
 *
 * An open type alone does not say so. A chat standing under a parent it needs membership in is
 * answered by that parent - Chat::checkAccess() hands the answer over the whole required chain - and
 * the registry of the tree allows an open channel under a closed project. The shared code describes
 * such a chat as reachable by every employee of the portal, while the messenger shows it only to the
 * members of the project, and the general search surface, which runs no re-check of its own, would
 * answer with its text.
 *
 * The chain is walked exactly as far as the access filters of the messenger join it. A tree deeper
 * than that, and one whose parent the table no longer holds, is answered as unreachable: the named
 * codes of the members are the narrower of the two answers, and the direction of the model is the
 * undershow.
 */
final class OpenCodeReachResolver
{
	/** Ancestors read by one query. A portion of the archive walk holds no more chats than this. */
	private const CHAIN_PORTION = 500;

	/** @var array<int, bool> */
	private array $reachByChatId = [];

	/** @var array<int, array{isOpen: bool, requiresParentMembership: bool, parentId: int}|null> */
	private array $rowByChatId = [];

	public function __construct(
		private readonly TypeRegistry $typeRegistry,
	)
	{}

	public function isReached(Chat $chat): bool
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0)
		{
			// a chat without a row of its own answers for itself alone: nothing carries its parent
			return $chat->getChatType()->isOpen && !$chat->hasParent();
		}

		return $this->reachByChatId[$chatId] ??= $this->walkUp(
			$chat->getChatType()->isOpen,
			$chat->requiresParentMembership(),
			$chat->getParentChatId() ?? 0,
			isFresh: false,
		);
	}

	/**
	 * The same answer for a caller holding an identifier alone, and nothing of it is remembered: the
	 * callers of this one are the conversion of a chat and the queue behind it, and both ask exactly
	 * because the type of a chat has just moved. The whole chain is therefore read anew, straight from
	 * the table, which is where the state the entries are written against stands.
	 */
	public function isReachedById(int $chatId): bool
	{
		if ($chatId <= 0)
		{
			return false;
		}

		$row = $this->readRow($chatId, isFresh: true);

		return $row !== null
			&& $this->walkUp($row['isOpen'], $row['requiresParentMembership'], $row['parentId'], isFresh: true);
	}

	/**
	 * Reads the chains of the chats of a portion by levels, so that every ancestor the walk below asks
	 * about is already remembered. Asked chat by chat, a portion of five hundred messages of as many
	 * chats standing under as many parents costs a select per parent at every level of the tree, and the
	 * answers go with the portion, so the next one pays the whole of it anew.
	 *
	 * Only the chats an open type stands on are walked: the chain of any other one is never read at all,
	 * and neither is the one of a chat answering for itself.
	 *
	 * @param iterable<Chat> $chats
	 */
	public function rememberChainOf(iterable $chats): void
	{
		$parentIds = [];
		foreach ($chats as $chat)
		{
			if (!$chat->getChatType()->isOpen || !$chat->requiresParentMembership())
			{
				continue;
			}

			$parentId = $chat->getParentChatId() ?? 0;
			if ($parentId > 0)
			{
				$parentIds[$parentId] = true;
			}
		}

		for ($depth = 1; $parentIds !== [] && $depth <= ChatAncestorNavigator::DEFAULT_DEPTH; $depth++)
		{
			$this->readRows(array_keys($parentIds));

			$nextIds = [];
			foreach (array_keys($parentIds) as $chatId)
			{
				$row = $this->rowByChatId[$chatId] ?? null;
				if ($row !== null && $row['isOpen'] && $row['requiresParentMembership'] && $row['parentId'] > 0)
				{
					$nextIds[$row['parentId']] = true;
				}
			}

			$parentIds = $nextIds;
		}
	}

	public function forgetChat(int $chatId): void
	{
		unset($this->reachByChatId[$chatId], $this->rowByChatId[$chatId]);
	}

	public function forgetAllChats(): void
	{
		$this->reachByChatId = [];
		$this->rowByChatId = [];
	}

	private function walkUp(bool $isOpen, bool $requiresParentMembership, int $parentId, bool $isFresh): bool
	{
		if (!$isOpen)
		{
			return false;
		}

		for ($depth = 1; $requiresParentMembership && $parentId > 0; $depth++)
		{
			if ($depth > ChatAncestorNavigator::DEFAULT_DEPTH)
			{
				return false;
			}

			$row = $this->readRow($parentId, $isFresh);
			if ($row === null || !$row['isOpen'])
			{
				return false;
			}

			$requiresParentMembership = $row['requiresParentMembership'];
			$parentId = $row['parentId'];
		}

		return true;
	}

	/**
	 * @param bool $isFresh Reads the table past the remembered answer and replaces it.
	 * @return array{isOpen: bool, requiresParentMembership: bool, parentId: int}|null Null for a chat
	 *         the table no longer holds.
	 */
	private function readRow(int $chatId, bool $isFresh): ?array
	{
		if (!$isFresh && array_key_exists($chatId, $this->rowByChatId))
		{
			return $this->rowByChatId[$chatId];
		}

		$row = ChatTable::query()
			->setSelect(['TYPE', 'ENTITY_TYPE', 'PARENT_ID'])
			->where('ID', $chatId)
			->fetch()
		;

		if (!is_array($row))
		{
			return $this->rowByChatId[$chatId] = null;
		}

		return $this->rowByChatId[$chatId] = $this->buildRow($row);
	}

	/**
	 * One level of the chains of a portion, by one query per portion of that level: the identifiers of a
	 * level are as many as the chats it stands under, and an unbounded IN is the one thing a walk of the
	 * archive may not build. A chat already remembered is not asked about again - what stands is what
	 * was read for the portion that runs.
	 *
	 * @param int[] $chatIds
	 */
	private function readRows(array $chatIds): void
	{
		$unknownIds = array_values(array_diff($chatIds, array_keys($this->rowByChatId)));

		foreach (array_chunk($unknownIds, self::CHAIN_PORTION) as $chunk)
		{
			$rows = ChatTable::query()
				->setSelect(['ID', 'TYPE', 'ENTITY_TYPE', 'PARENT_ID'])
				->whereIn('ID', $chunk)
				->fetchAll()
			;

			// a chat the query brings no row for is remembered as one the table no longer holds: an empty
			// answer is an answer, and asking it once per message is the very cost this removes
			$this->rowByChatId += array_fill_keys($chunk, null);

			foreach ($rows as $row)
			{
				$this->rowByChatId[(int)$row['ID']] = $this->buildRow($row);
			}
		}
	}

	/**
	 * @param array<string, mixed> $row Row of the chat as the table holds it.
	 * @return array{isOpen: bool, requiresParentMembership: bool, parentId: int}
	 */
	private function buildRow(array $row): array
	{
		$type = $this->typeRegistry->getByLiteralAndEntity(
			(string)$row['TYPE'],
			$row['ENTITY_TYPE'] === null ? null : (string)$row['ENTITY_TYPE'],
		);

		return [
			'isOpen' => $type->isOpen,
			'requiresParentMembership' => $type->requiresParentMembership,
			'parentId' => (int)($row['PARENT_ID'] ?? 0),
		];
	}
}
