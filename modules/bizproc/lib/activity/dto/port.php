<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Bizproc\Activity\Enum\ActivityPortRole;
use Bitrix\Bizproc\Activity\Enum\ActivityPortType;
use Bitrix\Main\Type\Contract\Arrayable;

final class Port implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly string $id = '',
		/**	@deprecated */
		public readonly int $position = 0,
		public readonly string $title = '',
		public readonly ?ActivityPortType $type = null,
		public readonly bool $isActive = true,
		/**
		 * Meaning of the port for a machine reader, while the title stays the caption a person sees.
		 * Null when the block declares no role: an unknown value is not carried over as a free string.
		 */
		public readonly ?ActivityPortRole $role = null,
	) {}

	public static function fromArray(array $array): self
	{
		return new self(
			(string)($array['id'] ?? ''),
			(int)($array['position'] ?? ''),
			(string)($array['title'] ?? ''),
			ActivityPortType::tryFrom((string)($array['type'] ?? '')),
			!array_key_exists('isActive', $array) || (bool)$array['isActive'],
			ActivityPortRole::tryFrom((string)($array['role'] ?? '')),
		);
	}

	public function toArray(): array
	{
		$array = [
			'id' => $this->id,
			'title' => $this->title,
			'type' => $this->type?->value,
			'isActive' => $this->isActive,
		];

		// Conditional on purpose: the key states the role the block declared, and a port of a block that
		// declares none keeps the shape it had. Null there would say the same as an absent key.
		if ($this->role !== null)
		{
			$array['role'] = $this->role->value;
		}

		return $array;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
