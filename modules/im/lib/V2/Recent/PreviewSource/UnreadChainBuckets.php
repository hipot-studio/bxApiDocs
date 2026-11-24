<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

/**
 * The user's unread inside a collab scope folded into ancestor chains: one record per distinct
 * "chain L_0..L_maxDepth + per-level accessibility flags", holding the MAX(MESSAGE_ID) of every
 * unread row that shares it ({@see UnreadFreshnessQuery::fetchChains()}). The number of records is
 * bounded by the number of the user's unread chats, not by the collab's size.
 *
 * Folding ahead of the nearest-candidate rule cannot change its answer. The rule reads a row only
 * through the level ids and the accessibility flags, so rows sharing them are assigned to the same
 * candidate; the freshness of a bucket is a MAX, and MAX is associative, so the maximum over the
 * folded records of a group equals the maximum over its rows. No candidate outside
 * {@see observedChatIds()} can be assigned either: the rule only ever returns a level id of the
 * record it looks at, and the levels that set leaves out are not candidates of this collab at all,
 * which is why the caller may narrow the candidate query to the observed ids.
 */
final readonly class UnreadChainBuckets
{
	private const LEVEL_PREFIX = 'L_';
	private const ACCESS_PREFIX = 'ACC_';
	private const MESSAGE_ID = 'MESSAGE_ID';

	/**
	 * @param int[] $depths bucket depths the chains carry, ascending from 0
	 * @param array<string,array<string,int>> $chains folded chains keyed by their signature
	 * @param int $collabChatId the scope collab, which together with the chains decides which level
	 *        ids can be candidates at all ({@see observedChatIds()})
	 */
	private function __construct(
		private array $depths,
		private array $chains,
		private int $collabChatId,
	) {}

	/**
	 * @param iterable<array<string,mixed>> $rows rows of the flat unread statement, streamed
	 * @param int[] $depths
	 */
	public static function fromChainRows(iterable $rows, array $depths, int $collabChatId): self
	{
		$chains = [];
		foreach ($rows as $row)
		{
			$messageId = (int)($row[self::MESSAGE_ID] ?? 0);
			if ($messageId <= 0)
			{
				continue;
			}

			[$signature, $chain] = self::readChain($row, $depths);
			if ($messageId > ($chains[$signature][self::MESSAGE_ID] ?? 0))
			{
				$chain[self::MESSAGE_ID] = $messageId;
				$chains[$signature] = $chain;
			}
		}

		return new self($depths, $chains, $collabChatId);
	}

	/**
	 * The chat ids met on a chain level that a candidate query of this collab could return at all -
	 * the collab itself and the levels standing directly under it. Every other level is dropped: a
	 * candidate is the collab or one of its direct children
	 * ({@see CollabCandidateQuery}), so the ids left out could never have come back as candidates and
	 * {@see assign()} would never have seen them. The narrowing therefore shortens the IN list without
	 * touching the assignment.
	 *
	 * A level whose parent stands above the deepest bucket is kept: the chain does not carry that
	 * parent, so its candidacy cannot be ruled out here.
	 *
	 * @return int[]
	 */
	public function observedChatIds(): array
	{
		$observed = [];
		foreach ($this->chains as $chain)
		{
			foreach ($this->depths as $index => $depth)
			{
				$chatId = $chain[self::LEVEL_PREFIX . $depth];
				if ($chatId > 0 && $this->standsInCandidateScope($chain, $index, $chatId))
				{
					$observed[$chatId] = true;
				}
			}
		}

		return array_keys($observed);
	}

	/**
	 * @param array<string,int> $chain
	 * @param int $index position of the level inside {@see $depths}, not its depth
	 */
	private function standsInCandidateScope(array $chain, int $index, int $chatId): bool
	{
		if ($chatId === $this->collabChatId)
		{
			return true;
		}

		$parentDepth = $this->depths[$index + 1] ?? null;
		if ($parentDepth === null)
		{
			return true;
		}

		return $chain[self::LEVEL_PREFIX . $parentDepth] === $this->collabChatId;
	}

	/**
	 * @param int[] $candidateIds
	 * @return array<int,int> candidate chat id => freshest unread MESSAGE_ID of its bucket
	 */
	public function assign(array $candidateIds): array
	{
		$candidateSet = array_fill_keys(array_map('intval', $candidateIds), true);
		if (empty($candidateSet))
		{
			return [];
		}

		$freshUnreadByChat = [];
		foreach ($this->chains as $chain)
		{
			$rootChatId = self::nearestCandidate($chain, $this->depths, $candidateSet);
			if ($rootChatId === null)
			{
				continue;
			}

			$messageId = $chain[self::MESSAGE_ID];
			if (!isset($freshUnreadByChat[$rootChatId]) || $messageId > $freshUnreadByChat[$rootChatId])
			{
				$freshUnreadByChat[$rootChatId] = $messageId;
			}
		}

		return $freshUnreadByChat;
	}

	/**
	 * Assigns a chain to its closest candidate ancestor, then keeps it only if every level up to and
	 * including that ancestor is accessible. Returns the candidate chat id, or null when the chain has
	 * no candidate ancestor or a level below the candidate is inaccessible.
	 *
	 * The single owner of the rule: {@see UnreadFreshnessQuery::fetchForMembers()} applies it per raw
	 * row of the fan-out statement, {@see assign()} applies it per folded chain.
	 *
	 * @param array<string,mixed> $chain
	 * @param int[] $depths
	 * @param array<int,true> $candidateSet
	 */
	public static function nearestCandidate(array $chain, array $depths, array $candidateSet): ?int
	{
		$rootDepth = null;
		foreach ($depths as $depth)
		{
			$chatId = (int)($chain[self::LEVEL_PREFIX . $depth] ?? 0);
			if ($chatId > 0 && isset($candidateSet[$chatId]))
			{
				$rootDepth = $depth;

				break;
			}
		}

		if ($rootDepth === null)
		{
			return null;
		}

		for ($depth = 1; $depth <= $rootDepth; $depth++)
		{
			if ((int)($chain[self::ACCESS_PREFIX . $depth] ?? 0) !== 1)
			{
				return null;
			}
		}

		return (int)$chain[self::LEVEL_PREFIX . $rootDepth];
	}

	/**
	 * @param array<string,mixed> $row
	 * @param int[] $depths
	 * @return array{0: string, 1: array<string,int>} the chain's signature and its normalized levels
	 */
	private static function readChain(array $row, array $depths): array
	{
		$chain = [];
		$signature = '';
		foreach ($depths as $depth)
		{
			$chatId = (int)($row[self::LEVEL_PREFIX . $depth] ?? 0);
			$chain[self::LEVEL_PREFIX . $depth] = $chatId;
			$signature .= $chatId . ':';

			if ($depth === 0)
			{
				continue;
			}

			$isAccessible = (int)($row[self::ACCESS_PREFIX . $depth] ?? 0);
			$chain[self::ACCESS_PREFIX . $depth] = $isAccessible;
			$signature .= $isAccessible . ':';
		}

		return [$signature, $chain];
	}
}
