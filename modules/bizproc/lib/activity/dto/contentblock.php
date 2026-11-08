<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * Display-only payload rendered inside a node's content block on the designer canvas.
 *
 * It also feeds the node settings panel, which renders the labelled values instead of the joined string.
 *
 * Resolved on demand from the activity's current configuration; it is never persisted
 * into activity Properties, the workflow template, runtime or export.
 */
final class ContentBlock implements Arrayable
{
	/**
	 * @param string $text Joined display string; this is what the canvas renders.
	 * @param list<ContentBlockItem> $items Labelled values for the settings panel, in display order.
	 *        A different presentation of the same data for a different surface, so the labels are not
	 *        duplicated into $text.
	 * @throws ArgumentTypeException when an entry of $items is not a ContentBlockItem. Providers build
	 *         the block inside the fail-closed catch of ContentBlockResolver, so a broken one lands in
	 *         the log instead of disappearing silently.
	 */
	public function __construct(
		public readonly string $text,
		public readonly array $items = [],
	)
	{
		foreach ($this->items as $item)
		{
			if (!($item instanceof ContentBlockItem))
			{
				throw new ArgumentTypeException('items', ContentBlockItem::class);
			}
		}
	}

	public function toArray(): array
	{
		$result = ['text' => $this->text];

		// Rebuilt without the source keys, so the payload stays a list and not an object.
		$items = array_map(
			static fn(ContentBlockItem $item) => $item->toArray(),
			array_values($this->items),
		);

		// The key stays out of the payload without items: the node catalog and the template load
		// serialize this array, and an extra key would reach every consumer of the previous shape.
		if ($items)
		{
			$result['items'] = $items;
		}

		return $result;
	}
}
