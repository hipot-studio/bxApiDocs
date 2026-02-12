<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

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
	) {}

	public static function fromArray(array $array): self
	{
		return new self(
			(string)($array['id'] ?? ''),
			(int)($array['position'] ?? ''),
			(string)($array['title'] ?? ''),
			ActivityPortType::tryFrom((string)($array['type'] ?? '')),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'type' => $this->type?->value,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
