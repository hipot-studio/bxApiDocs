<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Entity;

use Bitrix\Im\V2\Common\Collection\ArrayAccessTrait;
use Bitrix\Im\V2\Common\Collection\CountableTrait;
use Bitrix\Im\V2\Common\Collection\IteratorAggregateTrait;

/**
 * Map of userId => counter (int)
 * @implements \IteratorAggregate<int, int>
 * @implements \ArrayAccess<int, int>
 */
final class UsersCounterMap implements \IteratorAggregate, \Countable, \ArrayAccess
{
	use IteratorAggregateTrait;
	use CountableTrait;
	use ArrayAccessTrait;
	/** @var array<int, int> userId => counter */
	private array $counters = [];

	protected function &getArray(): array
	{
		return $this->counters;
	}

	/** @return array<int, int> */
	public function getRaw(): array
	{
		return $this->counters;
	}

	public function add(int $userId, int $counter): self
	{
		$this->counters[$userId] = $counter;

		return $this;
	}

	public function getByUserId(int $userId): int
	{
		return $this->counters[$userId] ?? 0;
	}

	/** @return int[] */
	public function getUserIds(): array
	{
		return array_keys($this->counters);
	}

	/** @param array<int, int> $data userId => counter */
	public static function fromArray(array $data): self
	{
		$map = new self();
		$map->counters = $data;

		return $map;
	}
}
