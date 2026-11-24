<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

use Bitrix\Main\ArgumentException;

/**
 * An ordered list of alternatives, such as the path of a series or the form of a banner: the first
 * matched alternative wins, not matched passes the turn on, and unknown stops the choice. An
 * alternative may carry a nested choice, the way a path carries the choice of its step.
 *
 * What was chosen is reported by the name of each choice, so a nested choice never repeats the name of
 * a choice above it; choices of different alternatives never meet and may share one.
 */
final class Choice
{
	/**
	 * @param array<string, ConditionGroup> $alternatives code => conditions, in the order they are tried
	 * @param array<string, Choice> $next code of an alternative => the choice made inside it
	 * @throws ArgumentException
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $alternatives,
		public readonly array $next = [],
	)
	{
		$foreign = array_diff_key($next, $alternatives);
		if ($foreign !== [])
		{
			throw new ArgumentException(
				'Nested choices of "' . $name . '" belong to no alternative: ' . implode(', ', array_keys($foreign)),
				'next'
			);
		}

		if (in_array($name, $this->getNestedNames(), true))
		{
			throw new ArgumentException('A choice nested in "' . $name . '" repeats its name', 'next');
		}
	}

	/**
	 * @return string[] names of every choice below this one, in all alternatives
	 */
	private function getNestedNames(): array
	{
		$names = [];
		foreach ($this->next as $nested)
		{
			$names[] = $nested->name;
			array_push($names, ...$nested->getNestedNames());
		}

		return $names;
	}
}
