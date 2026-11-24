<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Services\Share;

/**
 * The entry of the journal a refusal was given, and what that entry is allowed to say.
 *
 * An entry of a link reports the refusals of that link alone. When the entries of a window run out, every
 * refusal left folds into one entry shared by the links that did not get one: it stands for a group, so
 * naming any single link in it would report the refusals of the whole group as the refusals of that link.
 *
 * @see GuestRefusalJournalThrottle
 */
final readonly class GuestRefusalJournalEntry
{
	private function __construct(
		public bool $namesLink,
	)
	{
	}

	public static function ofLink(): self
	{
		return new self(true);
	}

	public static function ofUnnamedLinks(): self
	{
		return new self(false);
	}
}
