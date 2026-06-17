<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Directory;

final readonly class DirectoriesSettings
{
	/**
	 * @param DirectoryItem[] $items
	 */
	public function __construct(
		public array $items,
		public ?AssignedDirectory $outcome,
		public ?AssignedDirectory $trash,
		public ?AssignedDirectory $spam,
		public int $maxLevel,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'items' => array_map(static fn (DirectoryItem $i) => $i->toArray(), $this->items),
			'assignedDirectories' => [
				'outcome' => $this->outcome?->toArray(),
				'trash' => $this->trash?->toArray(),
				'spam' => $this->spam?->toArray(),
			],
			'maxLevel' => $this->maxLevel,
		];
	}
}
