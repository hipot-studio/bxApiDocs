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
		public readonly bool $isConnectionPort = false,
		public readonly ?ActivityPortType $type = null,
	) {}

	public static function fromArray(array $array): self
	{
		return new self(
			(string)($array['id'] ?? ''),
			(int)($array['position'] ?? ''),
			(string)($array['title'] ?? ''),
			(bool)($array['isConnectionPort'] ?? false),
			ActivityPortType::tryFrom((string)($array['type'] ?? '')),
		);
	}

	public function toArray(): array
	{
		$port = [
			'id' => $this->id,
			'title' => $this->title,
			'type' => $this->type?->value,
		];
		if ($this->isConnectionPort === true)
		{
			$port['isConnectionPort'] = true;
		}

		return $port;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
