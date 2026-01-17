<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Main\Type\Contract\Arrayable;

final class Port implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly string $id = '',
		public readonly int $position = 0,
		public readonly string $title = '',
	) {}

	public static function fromArray(array $array): self
	{
		return new self(
			(string)($array['id'] ?? ''),
			(int)($array['position'] ?? ''),
			(string)($array['title'] ?? ''),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'position' => $this->position,
			'title' => $this->title,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
