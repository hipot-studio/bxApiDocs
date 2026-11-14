<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

/**
 * What a portion of the presence list is asked with (`presence.list.v2`), brought within the bounds of the contract
 * before anything is read: the search trimmed the way the client trims it and cut to a hundred characters, fifty rows
 * at the most, and an empty cursor taken for the first portion.
 */
final readonly class PresenceListQuery
{
	/** The most rows one portion holds. */
	public const MAX_LIMIT = 50;

	/** The longest search, in characters. */
	public const MAX_QUERY_LENGTH = 100;

	/**
	 * The spaces the trim of JavaScript takes off a string: the white space and the line terminators of ECMAScript.
	 * Not \s or \v, which take the next line of the old encodings, U+0085, as well. The start is trimmed before the
	 * search is cut and the end after it, so neither pattern ever runs over more than the first hundred characters.
	 */
	private const LEADING_SPACES = '/^[\t\n\x0B\f\r\p{Zs}\x{2028}\x{2029}\x{FEFF}]++/u';
	private const TRAILING_SPACES = '/[\t\n\x0B\f\r\p{Zs}\x{2028}\x{2029}\x{FEFF}]++$/uD';

	/** The search without the spaces around it, a hundred characters at the most; empty for no search. */
	public string $query;

	/** The next cursor of the portion before; null for the first portion. */
	public ?string $cursor;

	/** The most rows of the portion, from one to fifty. */
	public int $limit;

	/**
	 * @param int $timestamp a moment of the date, zero for today
	 * @param array $entities the selector of the map, empty for the team of the viewer
	 * @param string|null $tab the code of the tab; one the date does not have gives way to its default
	 * @param bool $withMapPoints the points of the map beside the list are counted
	 * @param bool $withIndexAge the answer says how old the index it was cut from is: the client counts the minute it
	 *     keeps the answer for from that age, so the card is cut from the index as well
	 */
	public function __construct(
		public int $timestamp = 0,
		public array $entities = [],
		public ?string $tab = null,
		string $query = '',
		?string $cursor = null,
		int $limit = 30,
		public bool $withMapPoints = false,
		public bool $withIndexAge = false,
	)
	{
		$this->query = self::trimEnd(mb_substr(self::trimStart($query), 0, self::MAX_QUERY_LENGTH, 'UTF-8'));
		$this->cursor = $cursor === '' ? null : $cursor;
		$this->limit = min(max($limit, 1), self::MAX_LIMIT);
	}

	private static function trimStart(string $value): string
	{
		return preg_replace(self::LEADING_SPACES, '', $value) ?? ltrim($value);
	}

	private static function trimEnd(string $value): string
	{
		return preg_replace(self::TRAILING_SPACES, '', $value) ?? rtrim($value);
	}
}
