<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryFilter;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionarySort;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryType;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryValue;
use Bitrix\Crm\V2\Internal\Exception\Dictionary\DictionaryValueGoneException;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;

/**
 * Access to the values of the seven basic CRM dictionaries. A value is addressed by the pair
 * (dictionary, identifier) and by nothing else: the dictionary is part of every request, and the
 * condition selecting it is the invariant of every query - a value living in another dictionary is
 * not found and not touched, and so is a stage of a funnel, which the same table keeps.
 *
 * LEGACY TYPES DO NOT LEAVE. Implementations hide the legacy status layer
 * ({@see \Bitrix\Crm\StatusTable}, {@see \Bitrix\Crm\EO_Status}, {@see \CCrmStatus}) and hand
 * {@see DictionaryValue} upwards. The internal record key of a row is hidden with them: nothing above
 * this boundary may address a value by it, and no signature here names it.
 *
 * No read method throws: a value that does not exist is `null`, a dictionary whose values cannot be
 * read is an empty set (the rule of {@see \Bitrix\Crm\V2\Internal\Repository\Category\StageRepositoryInterface}).
 *
 * Nothing is filtered, ordered or paged after the rows are read: a read asks the storage for what it
 * is going to hand up and for nothing more.
 *
 * A write is an adapter over the same boundary and nothing more: it takes the fields the boundary
 * accepts, hands the refusal of the boundary up as the boundary wrote it, and decides nothing. A refused
 * write has changed nothing and states nothing about it either: there is no caller above that undoes one. Which
 * identifier may be given, what a name may look like, whether a shipped value may be deleted - all of
 * that belongs to the scenario above and is not checked here a second time.
 *
 * WRITES OF ONE VALUE HAPPEN ONE AT A TIME. Every write reads storage before it changes it and the table
 * makes no such read hold, so an implementation holds the writes addressing one pair (dictionary,
 * identifier) to one at a time. Writers past this boundary are not held: the promise is that two callers
 * of this interface do not interleave, and not that the row cannot move under one of them.
 *
 * Addressing a value that is not there is therefore not a refusal but a raise: the scenario above has
 * just read the value to check it exists, and a value gone between that read and the write is the one
 * case a write cannot report by itself. The scenario recognizes it and answers for it.
 *
 * @internal
 */
interface DictionaryValueRepositoryInterface
{
	/**
	 * The stored value of a successful write, inside {@see Result::getData()}. A deletion carries no
	 * data: what it wrote about is gone.
	 */
	public const DATA_KEY_VALUE = 'value';

	/**
	 * The value $id of the dictionary $type, or `null` when that dictionary has no such value.
	 *
	 * A dictionary holding the identifier twice is an invariant this domain declares rather than
	 * resolves: which of the rows is read is then not defined.
	 */
	public function getById(DictionaryType $type, string $id): ?DictionaryValue;

	/**
	 * The values of the dictionary $type the filter selects, in the order $sort asks for and cut to the
	 * page $pager asks for.
	 *
	 * The order is unambiguous even where the ordered field is not: the internal record key is appended
	 * to it as the last key, so two values sharing a `sort` keep their relative places between one page
	 * and the next. A caller cannot name that key and does not have to.
	 *
	 * Nothing asked for means: every value of the dictionary, in the order the dictionary itself is kept
	 * in, in one page as big as the dictionary. A page limit of its own this boundary has none - the
	 * limit belongs to the transport above it.
	 *
	 * @return list<DictionaryValue>
	 */
	public function getList(
		DictionaryType $type,
		?DictionaryFilter $filter = null,
		?DictionarySort $sort = null,
		?PagerInterface $pager = null,
	): array;

	/**
	 * Creates a value in the dictionary $type and hands it up inside {@see self::DATA_KEY_VALUE},
	 * read back from storage rather than assembled from the input.
	 *
	 * $id absent means the identifier is generated: the value gets whichever one the boundary makes up,
	 * and a client learns it from the value handed up. $sort absent means the position the boundary
	 * gives a new value, and the same applies.
	 *
	 * An identifier the boundary refuses to store - one already taken above all - is a refusal of the
	 * boundary and not an exception: it arrives as a failed result carrying the text the boundary wrote.
	 * An exception that is not a refusal of the boundary at all - a failed query above all - is not turned
	 * into a result: it says the storage of the portal is broken rather than that the write was turned
	 * down, and it travels out as it is.
	 */
	public function add(DictionaryType $type, ?string $id, string $name, ?int $sort): Result;

	/**
	 * Changes the value $id of the dictionary $type and hands the stored value up inside
	 * {@see self::DATA_KEY_VALUE}.
	 *
	 * A field left out keeps what it holds. That is a promise of this method rather than of the
	 * boundary: the boundary writes the position on every change and falls back to a default position
	 * when it gets none, so a change that leaves the position alone carries the current one - taken
	 * from the very row the value was addressed by, with no read of its own. The promise holds for a
	 * value standing at a position the boundary would replace rather than keep - zero or less - as well:
	 * such a change is written past the boundary instead of through it.
	 *
	 * The identifier is not among the arguments at all: it is the address of the value and the boundary
	 * refuses to move a value anywhere.
	 *
	 * @throws DictionaryValueGoneException The dictionary has no value $id any more.
	 */
	public function update(DictionaryType $type, string $id, ?string $name, ?int $sort): Result;

	/**
	 * Erases the value $id of the dictionary $type. A successful result carries no data.
	 *
	 * Elements referring to the value are not looked at: the reference stays behind pointing at nothing,
	 * which is what this domain does today and keeps doing.
	 *
	 * @throws DictionaryValueGoneException The dictionary has no value $id any more.
	 */
	public function delete(DictionaryType $type, string $id): Result;
}
