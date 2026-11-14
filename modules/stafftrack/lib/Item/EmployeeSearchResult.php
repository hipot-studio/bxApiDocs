<?php

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;

final class EmployeeSearchResult implements Arrayable
{
	/**
	 * @param EmployeeSearchMatch[] $matches
	 */
	public function __construct(
		public array $matches = [],
		public bool $hasDismissed = false,
	) {}

	public static function empty(): self
	{
		return new self();
	}

	public function toArray(): array
	{
		return [
			'employees' => array_map(
				static fn (EmployeeSearchMatch $match): array => $match->toArray(),
				array_values($this->matches),
			),
			'hasDismissed' => $this->hasDismissed,
		];
	}
}
