<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Entity;

use Bitrix\Im\V2\Common\Collection\ArrayAccessTrait;
use Bitrix\Im\V2\Common\Collection\CountableTrait;
use Bitrix\Im\V2\Common\Collection\IteratorAggregateTrait;

final class ChatsCounterMap implements \IteratorAggregate, \Countable, \ArrayAccess
{
	use IteratorAggregateTrait;
	use CountableTrait;
	use ArrayAccessTrait;
	/** @var array<int, int> chatId => counter */
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

	public function add(int $chatId, int $counter): self
	{
		$this->counters[$chatId] = $counter;

		return $this;
	}

	public function getByChatId(int $chatId): int
	{
		return $this->counters[$chatId] ?? 0;
	}

	/** @return int[] */
	public function getChatIds(): array
	{
		return array_keys($this->counters);
	}

	/** @param array<int, int> $data chatId => counter */
	public static function fromArray(array $data): self
	{
		$map = new self();
		$map->counters = $data;

		return $map;
	}
}
