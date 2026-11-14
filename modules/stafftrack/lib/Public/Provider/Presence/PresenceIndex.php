<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;

/**
 * The team of a date the presence list is cut from: every employee in the group of his work day, found by the query
 * of the list and ordered by the alphabet of the language of the interface.
 *
 * The order is made of the names and the id alone, so neither the state of the day nor a mark on the row moves
 * anybody. The index is built on every request: it sorts only the rows the query leaves, and only when asked.
 */
final class PresenceIndex
{
	/** @var PresenceIndexRow[] */
	private array $rows;

	/** @var PresenceIndexRow[]|null */
	private ?array $rowsInTheAlphabet = null;

	/**
	 * @param PresenceIndexRow[] $rows
	 * @param 'past'|'today'|'future' $dateKind
	 * @param \Collator|null $collator the alphabet of the names; without one they are compared byte by byte
	 */
	public function __construct(
		array $rows,
		private readonly string $dateKind,
		private readonly ?\Collator $collator,
	)
	{
		$this->rows = array_values($rows);
	}

	/**
	 * The alphabet of a language, the language of the interface by default. That one is asked for by its code
	 * rather than its Bitrix id: ICU knows no ua or kz and reads sc or la as other languages. A language without
	 * a code is asked for by its id.
	 *
	 * @param string|null $language the locale of the alphabet
	 * @return \Collator|null null without intl
	 */
	public static function collatorFor(?string $language = null): ?\Collator
	{
		if (!class_exists(\Collator::class))
		{
			return null;
		}

		return \Collator::create($language ?? self::getInterfaceLocale());
	}

	/**
	 * The employees whose shown name or position has the query in it, the case ignored. It narrows every tab
	 * at once, so the numbers of the tabs count what was found.
	 */
	public function matching(string $query): self
	{
		$query = trim($query);
		if ($query === '')
		{
			return $this;
		}

		return new self(
			array_filter($this->rows, static fn (PresenceIndexRow $row): bool => self::isFound($row, $query)),
			$this->dateKind,
			$this->collator,
		);
	}

	/**
	 * @return list<array{code: string, count: int}> every tab of the date in its order, the empty ones included
	 */
	public function tabCounts(): array
	{
		$counts = [];
		foreach (PresenceTab::ofDate($this->dateKind) as $tab)
		{
			$counts[] = [
				'code' => $tab->value,
				'count' => count($this->heldBy($tab, $this->rows)),
			];
		}

		return $counts;
	}

	/**
	 * @return PresenceIndexRow[] the rows of the tab in the alphabet
	 */
	public function rowsOf(PresenceTab $tab): array
	{
		return $this->heldBy($tab, $this->getRowsInTheAlphabet());
	}

	/**
	 * The rows of the tab strictly after the key of the cursor, in the alphabet. The key is a place in the alphabet
	 * rather than a position in the tab, so whoever left or joined the tab since the portion before shifts nothing.
	 *
	 * @param string|null $cursor the next cursor of the portion before, null for the first portion
	 * @param int $limit the most rows the portion holds, one at the least
	 * @return array{rows: PresenceIndexRow[], nextCursor: string|null} the cursor is null when no row of the tab
	 *     follows the portion
	 * @throws ArgumentException when the cursor cannot be read
	 */
	public function portion(PresenceTab $tab, ?string $cursor, int $limit): array
	{
		$rows = $this->rowsOf($tab);
		$start = $cursor === null ? 0 : $this->countUpTo(PresenceCursor::read($cursor), $rows);

		$portion = array_slice($rows, $start, max(1, $limit));
		$hasMore = $start + count($portion) < count($rows);

		return [
			'rows' => $portion,
			'nextCursor' => $hasMore ? PresenceCursor::of($portion[array_key_last($portion)]) : null,
		];
	}

	/**
	 * @param PresenceIndexRow[] $rows
	 * @return PresenceIndexRow[]
	 */
	private function heldBy(PresenceTab $tab, array $rows): array
	{
		return array_values(array_filter(
			$rows,
			fn (PresenceIndexRow $row): bool => $tab->holds($row->group, $row->hasCheckIns, $this->dateKind),
		));
	}

	/**
	 * @return PresenceIndexRow[]
	 */
	private function getRowsInTheAlphabet(): array
	{
		if ($this->rowsInTheAlphabet === null)
		{
			$keyedRows = array_map(static fn (PresenceIndexRow $row): array => [$row->sortKey(), $row], $this->rows);
			usort($keyedRows, fn (array $a, array $b): int => $this->compareKeys($a[0], $b[0]));

			$this->rowsInTheAlphabet = array_column($keyedRows, 1);
		}

		return $this->rowsInTheAlphabet;
	}

	/**
	 * @param array{0: string, 1: string, 2: int} $key
	 * @param PresenceIndexRow[] $rows in the alphabet
	 * @return int how many of the rows stand at the key or before it
	 */
	private function countUpTo(array $key, array $rows): int
	{
		$count = 0;
		foreach ($rows as $row)
		{
			if ($this->compareKeys($row->sortKey(), $key) > 0)
			{
				break;
			}

			$count++;
		}

		return $count;
	}

	/**
	 * Part by part: glued, the last name of one employee would run into the name of another.
	 *
	 * @param array{0: string, 1: string, 2: int} $a
	 * @param array{0: string, 1: string, 2: int} $b
	 */
	private function compareKeys(array $a, array $b): int
	{
		return
			$this->compareNames($a[0], $b[0])
			?: $this->compareNames($a[1], $b[1])
			?: $a[2] <=> $b[2]
		;
	}

	private function compareNames(string $a, string $b): int
	{
		if ($this->collator === null)
		{
			return strcmp(mb_strtolower($a), mb_strtolower($b));
		}

		return (int)$this->collator->compare($a, $b);
	}

	private static function isFound(PresenceIndexRow $row, string $query): bool
	{
		return
			mb_stripos(self::getShownName($row), $query) !== false
			|| mb_stripos($row->position, $query) !== false
		;
	}

	/**
	 * The name the row shows: the formatted one and, when it is empty, the name with the last name.
	 */
	private static function getShownName(PresenceIndexRow $row): string
	{
		return $row->displayName !== '' ? $row->displayName : trim($row->name . ' ' . $row->lastName);
	}

	private static function getInterfaceLocale(): string
	{
		$code = (string)Context::getCurrent()?->getLanguageObject()?->getCode();

		return $code !== '' ? $code : (string)Loc::getCurrentLang();
	}
}
