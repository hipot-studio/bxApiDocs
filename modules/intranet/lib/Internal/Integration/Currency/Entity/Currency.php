<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Currency\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

class Currency implements EntityInterface, Arrayable
{
	public function __construct(
		public readonly string $code,
		public readonly string $name,
	)
	{
	}

	public static function createFromArray(array $currency): static
	{
		return new static(
			code: $currency['CURRENCY'] ?? '',
			name: $currency['NAME'] ?? '',
		);
	}

	public function getId(): string
	{
		return $this->code;
	}

	public function toArray(): array
	{
		return [
			'code' => $this->code,
			'name' => $this->name,
		];
	}
}
