<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Entity\ValueObjectInterface;

class Source implements ValueObjectInterface
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?SourceType $type = null,
		public readonly ?array $data = null
	)
	{
	}

	public function toArray(): array
	{
		return [
			'type' => $this->type?->value,
			'data' => $this->data,
		];
	}

	public static function mapFromArray(array $props = []): static
	{
		return new static(
			type: static::mapBackedEnum($props, 'type', SourceType::class),
			data: [
				'entityId' => static::mapInteger($props, 'entityId'),
				'subEntityId' => static::mapInteger($props, 'subEntityId'),
			],
		);
	}
}
