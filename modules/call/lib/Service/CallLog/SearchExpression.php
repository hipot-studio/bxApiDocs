<?php

declare(strict_types=1);

namespace Bitrix\Call\Service\CallLog;

use Bitrix\Call\Internals\OwnerTerm;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\Search\Content;

/**
 * The fulltext expression the search of the journal is matched against.
 *
 * The content of a row carries the reserved term of its owner ({@see OwnerTerm}), and the query of a person
 * must never be answered by one. The guard lives here, so every source of the search gets a guarded expression.
 */
class SearchExpression
{
	/**
	 * The shortest word a query of a single word may carry: the word is matched as a prefix, so one letter is
	 * answerable. Not zero - the core reads zero as "not given" and falls back to the size of the portal.
	 */
	public const SINGLE_WORD_MIN_TOKEN_SIZE = 1;

	private const WILDCARD = '*';

	/** What the stand-in of a reserved word carries where a term carries the id of an owner. */
	private const NEVER_MATCHED_SUFFIX = 'none';

	/**
	 * Builds the expression of the query.
	 *
	 * @param string $search The query as the person typed it.
	 * @return string An empty string when the query carries no word the index can be asked about.
	 */
	public function build(string $search): string
	{
		$phrase = Content::prepareStringToken($search);
		$minTokenSize = $this->minTokenSizeOf($phrase);

		return Helper::matchAgainstWildcard(
			$this->protectedPhraseOf($phrase, $minTokenSize),
			self::WILDCARD,
			$minTokenSize,
		);
	}

	/**
	 * The words {@see self::build()} makes its expression of: in the form the index holds them and already
	 * guarded, for a consumer that puts them into a condition of its own. The core groups the words of a
	 * phrase into at most one "and" group, so only the brackets of the expression are lost.
	 *
	 * @param string $search The query as the person typed it.
	 * @return string[]
	 */
	public function parseProtectedWords(string $search): array
	{
		$phrase = Content::prepareStringToken($search);
		$minTokenSize = $this->minTokenSizeOf($phrase);
		$wordGroups = Helper::parseFulltextPhrase(
			$this->protectedPhraseOf($phrase, $minTokenSize),
			$minTokenSize,
		);

		return $wordGroups === [] ? [] : array_values($wordGroups[0]);
	}

	/**
	 * Whether the query may be a name: it carries a word that is not a number.
	 *
	 * The directory of users holds the id of an account as a word of its own
	 * ({@see \Bitrix\Main\UserTable::generateSearchUserContent()}), so a query of digits - a phone number for
	 * this list - would find accounts by their id.
	 *
	 * @param string $search The query as the person typed it.
	 * @return bool
	 */
	public function mayCarryAName(string $search): bool
	{
		foreach (Helper::splitWords(Content::prepareStringToken($search)) as $word)
		{
			if (!$this->isNumber((string)$word))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A pattern rather than ctype_digit(): the ctype extension is absent on portals. '/D' keeps a trailing
	 * line break from passing for a number.
	 */
	private function isNumber(string $word): bool
	{
		return preg_match('/^[0-9]+$/D', $word) === 1;
	}

	/**
	 * The phrase with every word able to reach the reserved family replaced by one no row carries.
	 *
	 * Replaced rather than dropped: a query of several words means "this and that", and dropping a word would
	 * widen the answer. A word too short for the index is left alone - the core drops it, while its stand-in
	 * would survive the size and become a required term the person never typed.
	 *
	 * @param string $phrase The query already in the form a row is written in.
	 * @param int|null $minTokenSize The size the phrase goes into the expression with.
	 */
	private function protectedPhraseOf(string $phrase, ?int $minTokenSize): string
	{
		$minSize = $this->effectiveMinTokenSize($minTokenSize);
		$words = Helper::splitWords($phrase);

		foreach ($words as $i => $word)
		{
			$isHeldByTheIndex = mb_strlen((string)$word) >= $minSize;
			if ($isHeldByTheIndex && $this->mayReachTheOwnerFamily((string)$word))
			{
				$words[$i] = self::neverMatchedWord();
			}
		}

		return implode(' ', $words);
	}

	/**
	 * A word is matched as a prefix, so it is dangerous both ways round: the prefix of the family begins with
	 * it, or it begins with the prefix of the family. Case is ignored, as the index ignores it.
	 */
	private function mayReachTheOwnerFamily(string $word): bool
	{
		return
			stripos(OwnerTerm::STORED_PREFIX, $word) === 0
			|| stripos($word, OwnerTerm::STORED_PREFIX) === 0;
	}

	/**
	 * A word inside the family that begins no term of it: letters where a term carries the digits of an id.
	 */
	private static function neverMatchedWord(): string
	{
		return Content::prepareStringToken(OwnerTerm::RESERVED_PREFIX . self::NEVER_MATCHED_SUFFIX);
	}

	/**
	 * A query of several words keeps the size of the portal: each of its words is required, and a word the
	 * index does not hold would empty the answer. Counted on the phrase before the guard, which may collapse
	 * two reserved words into one stand-in.
	 *
	 * @return int|null Null asks the core for the size of the portal.
	 */
	private function minTokenSizeOf(string $phrase): ?int
	{
		return count(Helper::splitWords($phrase)) === 1 ? self::SINGLE_WORD_MIN_TOKEN_SIZE : null;
	}

	/**
	 * The size the core judges the words by, asked of the very routine the core asks, so the guard holds no
	 * size of its own.
	 */
	private function effectiveMinTokenSize(?int $minTokenSize): int
	{
		return $minTokenSize ?? (int)Helper::getMinTokenSize();
	}
}
