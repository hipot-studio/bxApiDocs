<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class UserField extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?string $key = null,
		public readonly mixed $value = null,
	)
	{

	}

	public function getId(): string
	{
		return $this->key;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			key: static::mapString($props, 'key'),
			value: static::mapMixed($props, 'value'),
		);
	}

	public function toArray(): array
	{
		return [
			'key' => $this->key,
			'value' => $this->value,
		];
	}
}
