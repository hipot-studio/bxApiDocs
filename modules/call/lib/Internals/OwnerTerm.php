<?php

declare(strict_types=1);

namespace Bitrix\Call\Internals;

use Bitrix\Main\Search\Content;

/**
 * The reserved word that names the owner of a row of the journal index.
 *
 * The term puts the owner inside the searchable content, so the owner becomes a condition of the fulltext pass
 * rather than a filter over its answer. The format is shared by the write path, the pass that fills the rows
 * written earlier - it builds the stored form in SQL - and the read path.
 *
 * Two forms of one term: the raw one, 'zq8x', is handed to {@see \Bitrix\Main\Search\MapBuilder::addText()};
 * the stored one, 'md8k', lies in the row and is what the read path asks about. ROT13, the transform of the
 * whole content, turns the one into the other and leaves digits alone.
 */
final class OwnerTerm
{
	/** Rare at the start of a word in either language of the portal, so a name does not reach the family. */
	public const RESERVED_PREFIX = 'zq';

	/**
	 * Keeps any term of the family from being a prefix of another, so the owner 1 is not answered by the rows
	 * of the owners 10 and 100 under a wildcard. It also makes the shortest term four characters long, which
	 * a portal with ft_min_token_size raised to four still indexes.
	 */
	public const TERMINATOR = 'x';

	/** The prefix as it lies in the row. */
	public const STORED_PREFIX = 'md';

	/** The terminator as it lies in the row. */
	public const STORED_TERMINATOR = 'k';

	/**
	 * The term as the write path hands it over: untransformed, because the builder transforms the whole
	 * content, this term included.
	 *
	 * @param int $ownerId
	 * @return string
	 */
	public static function raw(int $ownerId): string
	{
		return self::RESERVED_PREFIX . self::normalize($ownerId) . self::TERMINATOR;
	}

	/**
	 * The term as it lies in the row and as the read path asks about it.
	 *
	 * @param int $ownerId
	 * @return string
	 */
	public static function stored(int $ownerId): string
	{
		return Content::prepareStringToken(self::raw($ownerId));
	}

	/**
	 * A hyphen would split the term into two words on the way in and negate it in a boolean fulltext
	 * expression on the way out.
	 */
	private static function normalize(int $ownerId): int
	{
		return max(0, $ownerId);
	}
}
