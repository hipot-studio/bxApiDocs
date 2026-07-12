<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\TariffLimit;

/**
 * @implements \IteratorAggregate<string, TariffRestriction>
 */
final class TariffRestrictionCollection implements \IteratorAggregate, \Countable, \JsonSerializable
{
	/** @var array<string, TariffRestriction> */
	private array $items = [];

	public function __construct(TariffRestriction ...$restrictions)
	{
		foreach ($restrictions as $restriction)
		{
			$code = $restriction->getCode();
			if (!isset($this->items[$code]))
			{
				$this->items[$code] = $restriction;
			}
		}
	}

	public function withAdded(TariffRestriction $restriction): self
	{
		if (isset($this->items[$restriction->getCode()]))
		{
			return $this;
		}

		$clone = clone $this;
		$clone->items[$restriction->getCode()] = $restriction;

		return $clone;
	}

	public function merge(self $other): self
	{
		$clone = clone $this;
		foreach ($other->items as $code => $restriction)
		{
			if (!isset($clone->items[$code]))
			{
				$clone->items[$code] = $restriction;
			}
		}

		return $clone;
	}

	public function has(string $code): bool
	{
		return isset($this->items[$code]);
	}

	public function get(string $code): ?TariffRestriction
	{
		return $this->items[$code] ?? null;
	}

	public function isEmpty(): bool
	{
		return $this->items === [];
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function getIterator(): \Generator
	{
		foreach ($this->items as $code => $item)
		{
			yield $code => $item;
		}
	}

	/**
	 * @return array<string, array>
	 */
	public function jsonSerialize(): array
	{
		$result = [];
		foreach ($this as $code => $item)
		{
			$result[$code] = $item->jsonSerialize();
		}

		return $result;
	}
}
