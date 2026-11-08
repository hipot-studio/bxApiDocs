<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

/**
 * The single place that decides what the placement of a prepared generation must say about
 * the reading of its letter. The import and the replay pass both ask it; neither keeps a
 * copy of the rule, or the two would part on the first edit of either.
 *
 * The answer is always an intention - read or unread the user is waiting to push - and never
 * a settled value. A settled one claims the server has confirmed it, so the first
 * synchronization of the folder after the switch would find the letter reported otherwise and
 * roll the state back; an intention is what that same synchronization turns into a command to
 * the server, which is how the state reaches the new source at all.
 *
 * Knows nothing of generations and never reads a row: it is given what was read for it.
 */
final class ReadStateIntent
{
	/** Read and unread the server has already confirmed */
	public const SETTLED_READ = 'Y';
	public const SETTLED_UNREAD = 'N';

	/** Read and unread of a local action, waiting to be pushed to the server */
	public const PENDING_READ = 'S';
	public const PENDING_UNREAD = 'U';

	private const PENDING_STATES = [self::PENDING_READ, self::PENDING_UNREAD];

	private const READ_STATES = [self::SETTLED_READ, self::PENDING_READ];

	/**
	 * @param bool $portalHasRead Whether the portal holds the letter read {@see portalHasRead()}.
	 * @param string $placementState The IS_SEEN the placement carries right now.
	 * @param bool $placementIsOfTheActiveGeneration Whether the row belongs to the source the user
	 *        is working in right now. False - the default - for a row of a generation being
	 *        prepared, which is every row the import and the replay pass ever write.
	 * @return string|null The value to write, null when the placement is left as it is.
	 */
	public static function forPlacement(
		bool $portalHasRead,
		string $placementState,
		bool $placementIsOfTheActiveGeneration = false,
	): ?string
	{
		/*
			A placement of the active generation waiting to push a state of its own carries the
			decision of the user, which has not reached the server yet, and nothing overwrites it:
			the state it would be overwritten with is the one the user has just changed.

			A placement of a generation being prepared is a different row entirely. The user cannot
			reach that source - it is hidden from them until the switch - so a waiting state there
			is never theirs: it is the one we wrote ourselves on an earlier run, out of a side of
			the portal that has since moved on. Guarding it would freeze our own stale answer, so
			it is recomputed like any other, and a waiting state simply reads as the reading it
			stands for.
		*/
		if ($placementIsOfTheActiveGeneration && in_array($placementState, self::PENDING_STATES, true))
		{
			return null;
		}

		$placementHasRead = in_array($placementState, self::READ_STATES, true);

		// The two sides agree: agreement is no write, not a write of the same value
		if ($portalHasRead === $placementHasRead)
		{
			return null;
		}

		return $portalHasRead ? self::PENDING_READ : self::PENDING_UNREAD;
	}

	/**
	 * The side of the portal: a letter is read there once any of its placements says so,
	 * whether the server of that placement knows it or not.
	 *
	 * @param string[] $states The IS_SEEN of the placements of the retained scope.
	 */
	public static function portalHasRead(array $states): bool
	{
		foreach ($states as $state)
		{
			if (in_array($state, self::READ_STATES, true))
			{
				return true;
			}
		}

		return false;
	}
}
