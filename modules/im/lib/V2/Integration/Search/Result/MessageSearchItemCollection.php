<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Result;

use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\RestConvertible;

/**
 * The portion of the output. Being PopupDataAggregatable is what brings the authors of the portion
 * into the standard 'users' key of the answer, in the very same format the rest of the messenger
 * already sends them in.
 *
 * @implements \IteratorAggregate<int, MessageSearchItem>
 */
final class MessageSearchItemCollection implements RestConvertible, PopupDataAggregatable, \IteratorAggregate, \Countable
{
	/** @var MessageSearchItem[] */
	private array $items;

	public function __construct(MessageSearchItem ...$items)
	{
		$this->items = $items;
	}

	public static function getRestEntityName(): string
	{
		return 'messages';
	}

	public function toRestFormat(array $option = []): array
	{
		return array_map(
			static fn (MessageSearchItem $item): array => $item->toRestFormat($option),
			$this->items,
		);
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		return new PopupData([new UserPopupItem($this->getAuthorIds())], $excludedList);
	}

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->items);
	}

	public function count(): int
	{
		return count($this->items);
	}

	/**
	 * @return MessageSearchItem[]
	 */
	public function getItems(): array
	{
		return $this->items;
	}

	public function getLast(): ?MessageSearchItem
	{
		return $this->items === [] ? null : $this->items[array_key_last($this->items)];
	}

	/**
	 * @return int[]
	 */
	private function getAuthorIds(): array
	{
		return array_values(array_unique(
			array_map(static fn (MessageSearchItem $item): int => $item->authorId, $this->items),
		));
	}
}
