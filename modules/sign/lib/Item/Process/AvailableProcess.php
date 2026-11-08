<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Sign\Contract;

/**
 * A process of the KEDO section an employee may start manually. The composition of the fields is a
 * contract of the client, so toArray() stays the single serialization of the row: a second one would
 * let two surfaces of the same list disagree about a process.
 */
final class AvailableProcess implements Contract\Item, Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $description,
		public readonly bool $hasParameters,
		public readonly bool $isConstantsTuned,
		public readonly int $modifiedTs,
		public readonly int $authorId,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'hasParameters' => $this->hasParameters,
			'isConstantsTuned' => $this->isConstantsTuned,
			'modifiedTs' => $this->modifiedTs,
			'authorId' => $this->authorId,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
