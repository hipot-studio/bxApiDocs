<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

use InvalidArgumentException;
use JsonSerializable;

final class AuditPage implements JsonSerializable
{
	/** @var AuditListItem[] */
	public readonly array $items;

	/**
	 * @param AuditListItem[] $items
	 */
	public function __construct(
		public readonly AuditState $state,
		array $items,
		public readonly int $page,
		public readonly int $pageSize,
		public readonly int $total,
	)
	{
		foreach ($items as $item)
		{
			if (!$item instanceof AuditListItem)
			{
				throw new InvalidArgumentException('Disk audit page items must be audit list items.');
			}
		}

		if ($page < 1 || $pageSize < 1 || $total < 0)
		{
			throw new InvalidArgumentException('Disk audit page metadata is invalid.');
		}

		$this->items = array_values($items);
	}

	public function hasNext(): bool
	{
		return $this->page * $this->pageSize < $this->total;
	}

	public function jsonSerialize(): array
	{
		return [
			'state' => $this->state->value,
			'items' => $this->items,
			'page' => $this->page,
			'pageSize' => $this->pageSize,
			'total' => $this->total,
			'hasNext' => $this->hasNext(),
		];
	}
}
