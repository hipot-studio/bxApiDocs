<?php

namespace Bitrix\ImOpenLines;

/**
 * Pure keyset-pagination helpers for imopenlines.session.history.get.
 *
 * Dependency-free by design: holds the REST page-size contract, the capability probe
 * by CIMChat::GetLastMessageLimit signature and the next-page metadata calculation, so
 * the logic is unit-testable in isolation from CRestServer / the read-path / the DB.
 */
class SessionHistoryPagination
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;

	/** Parameter count of CIMChat::GetLastMessageLimit once it carries the keyset/limit read-path (limit + lastId). */
	public const PAGINATED_READPATH_ARITY = 8;

	/**
	 * Clamps the requested page size into [1..MAX_LIMIT], defaulting empty/invalid input to
	 * DEFAULT_LIMIT. Aligns with the V2 keyset contract (BaseController::getLimit).
	 */
	public static function clampLimit(int $limit): int
	{
		if ($limit <= 0)
		{
			return self::DEFAULT_LIMIT;
		}

		return $limit > self::MAX_LIMIT ? self::MAX_LIMIT : $limit;
	}

	/**
	 * Pure capability predicate: the read-path is paginated once GetLastMessageLimit
	 * accepts the trailing limit/lastId parameters (arity >= PAGINATED_READPATH_ARITY).
	 * Kept dependency-free so it stays unit-testable without loading `im`.
	 */
	public static function isPaginatedArity(int $getLastMessageLimitArity): bool
	{
		return $getLastMessageLimitArity >= self::PAGINATED_READPATH_ARITY;
	}

	/**
	 * Runtime capability probe: inspects the installed CIMChat::GetLastMessageLimit signature.
	 * Detects the keyset/limit read-path by parameter arity instead of an `im` version threshold,
	 * so module coupling stays soft and install order / version bumps do not matter.
	 */
	public static function isReadPathPaginated(): bool
	{
		if (!class_exists(\CIMChat::class))
		{
			return false;
		}

		try
		{
			$arity = (new \ReflectionMethod(\CIMChat::class, 'GetLastMessageLimit'))->getNumberOfParameters();
		}
		catch (\ReflectionException)
		{
			return false;
		}

		return self::isPaginatedArity($arity);
	}

	/**
	 * Next-page metadata for a returned page of messages (DESC order, keyset by (DATE_CREATE, ID)).
	 *
	 * @param int[] $messageIds IDs of the returned page in read-path page order (order is significant;
	 *        DESC read-path yields newest -> oldest). DATE_CREATE is not monotonic with ID, so the
	 *        cursor cannot be derived by min(ID).
	 * @param int $appliedLimit limit actually passed to the read-path (0 = pagination off)
	 * @return array{nextId: int|null, hasNextPage: bool} nextId = last element of the page (oldest
	 *         message under DESC), i.e. the boundary to load older messages, else null.
	 */
	public static function computeMeta(array $messageIds, int $appliedLimit): array
	{
		$hasNextPage = $appliedLimit > 0 && count($messageIds) >= $appliedLimit;
		$nextId = $hasNextPage ? (int)$messageIds[array_key_last($messageIds)] : null;

		return ['nextId' => $nextId, 'hasNextPage' => $hasNextPage];
	}
}
