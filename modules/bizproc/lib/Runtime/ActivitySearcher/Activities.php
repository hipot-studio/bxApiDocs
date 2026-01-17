<?php

namespace Bitrix\Bizproc\Runtime\ActivitySearcher;

use ArrayIterator;
use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Mixins\ActivityFilterChecker;

final class Activities implements \IteratorAggregate, \JsonSerializable
{
	use ActivityFilterChecker;

	public const DEFAULT_SORT = ['SORT' => SORT_ASC, 'NAME' => SORT_ASC];

	/** @var Array<string, ActivityDescription>[] $items */
	private array $items;

	/**
	 * @param Array<string, ActivityDescription> $items
	 */
	public function __construct(array $items = [])
	{
		$this->items = $items;
	}

	/**
	 * @return ArrayIterator<ActivityDescription>
	 */
	public function getIterator(): ArrayIterator
	{
		return new ArrayIterator($this->items);
	}

	public function add(string $key, ActivityDescription $item): self
	{
		$this->items[$key] = $item;

		return $this;
	}

	public function addCollection(Activities $activities): self
	{
		foreach ($activities->getIterator() as $key => $item)
		{
			$this->add($key, $item);
		}

		return $this;
	}

	public function has(string $key): bool
	{
		return array_key_exists($key, $this->items);
	}

	public function sort(array $columns = self::DEFAULT_SORT): self
	{
		$items = $this->toArray();
		\Bitrix\Main\Type\Collection::sortByColumn($items, $columns);

		return new self(array_map(static fn(array $item) => ActivityDescription::makeFromArray($item), $items));
	}

	public function isEmpty(): bool
	{
		return empty($this->items);
	}

	public function computeDescriptionFilter(?array $documentType = null): self
	{
		/** @var ActivityDescription $description */
		foreach ($this->getIterator() as $description)
		{
			if ($description->getFilter() && !$this->checkActivityFilter($description->getFilter(), $documentType))
			{
				$description->setExcluded(true);
			}
		}

		return $this;
	}

	public function filter(callable $callback): self
	{
		$filtered = array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH);

		return new self($filtered);
	}

	/**
	 * @return Array<string, Array<string, mixed>> - ['key' => ['NAME' => '', 'DESCRIPTION' => '', ...]]
	 */
	public function toArray(): array
	{
		return array_map(static fn(ActivityDescription $item) => $item->toArray(), $this->items);
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
