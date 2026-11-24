<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

use Bitrix\Main\ArgumentException;

/**
 * Conditions joined by "all of" or "any of", in declared order. The group of a rule is the first
 * level and a group inside it the second; deeper nesting is rejected, so a rule stays a declaration
 * rather than an expression.
 */
final class ConditionGroup
{
	public const MAX_DEPTH = 2;

	/**
	 * @param array<Condition|ConditionGroup> $items
	 */
	private function __construct(
		private readonly bool $anyOf,
		private readonly array $items,
		private readonly int $depth,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 */
	public static function allOf(Condition|ConditionGroup ...$items): self
	{
		return self::create(false, $items);
	}

	/**
	 * @throws ArgumentException
	 */
	public static function anyOf(Condition|ConditionGroup ...$items): self
	{
		return self::create(true, $items);
	}

	public function isAnyOf(): bool
	{
		return $this->anyOf;
	}

	/**
	 * @return array<Condition|ConditionGroup> in declared order
	 */
	public function getItems(): array
	{
		return $this->items;
	}

	/**
	 * @param array<Condition|ConditionGroup> $items
	 * @throws ArgumentException
	 */
	private static function create(bool $anyOf, array $items): self
	{
		$depth = 1;
		foreach ($items as $item)
		{
			if ($item instanceof self)
			{
				$depth = max($depth, $item->depth + 1);
			}
		}

		if ($depth > self::MAX_DEPTH)
		{
			throw new ArgumentException('Condition groups nest no deeper than ' . self::MAX_DEPTH . ' levels', 'items');
		}

		return new self($anyOf, array_values($items), $depth);
	}
}
