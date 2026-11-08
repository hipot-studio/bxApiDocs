<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Dictionary;

use Bitrix\Main\ArgumentException;

/**
 * The order a caller asks the values of a dictionary in, keyed by field in the order the caller gave.
 *
 * A field that cannot be ordered by is refused here by {@see ArgumentException}, and so is a direction
 * this order has no meaning for: both are mistakes of the caller rather than outcomes of an operation,
 * and the transport lets neither through - it refuses an unknown direction of its own accord
 * ({@see \Bitrix\Rest\V3\Structure\Ordering\OrderStructure::create()}), so a client that mistyped one
 * is answered about its order rather than served in an order it did not ask for.
 *
 * THE LAST KEY IS NOT HERE. The order needs the internal record key appended to be unambiguous between
 * pages, and that key is no field of a dictionary value - it is neither published nor addressable. So the
 * repository appends it, and this object carries only what a client can name.
 *
 * @internal
 */
final class DictionarySort
{
	public const DIRECTION_ASC = 'ASC';
	public const DIRECTION_DESC = 'DESC';

	/** @var array<string, string> */
	private array $order = [];

	/** @param array<string, string> $order field of {@see DictionaryField} => direction */
	public function __construct(array $order = [])
	{
		foreach ($order as $field => $direction)
		{
			$this->order[$this->assertSortable((string)$field)->value] = self::assertDirection($direction);
		}
	}

	/**
	 * Never empty: with nothing asked for, values come in the order the dictionary itself is kept in.
	 *
	 * @return array<string, string> field of {@see DictionaryField} => direction
	 */
	public function getOrder(): array
	{
		return $this->order === [] ? [DictionaryField::Sort->value => self::DIRECTION_ASC] : $this->order;
	}

	private function assertSortable(string $field): DictionaryField
	{
		$resolved = DictionaryField::tryFrom($field);
		if ($resolved === null)
		{
			throw new ArgumentException("Unknown dictionary field: {$field}", 'order');
		}

		if (!$resolved->isSortable())
		{
			throw new ArgumentException("Dictionary values cannot be ordered by: {$field}", 'order');
		}

		return $resolved;
	}

	/** The direction as this order spells it, whatever case the caller wrote it in. */
	private static function assertDirection(string $direction): string
	{
		foreach ([self::DIRECTION_ASC, self::DIRECTION_DESC] as $known)
		{
			if (strcasecmp($direction, $known) === 0)
			{
				return $known;
			}
		}

		throw new ArgumentException("Unknown order direction: {$direction}", 'order');
	}
}
