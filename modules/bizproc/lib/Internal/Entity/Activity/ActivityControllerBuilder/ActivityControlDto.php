<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\ActivityControllerBuilder;

use Bitrix\Main\Type\Contract\Arrayable;

class ActivityControlDto implements Arrayable, \JsonSerializable
{
	private array $property;
	private mixed $value;

	public function __construct(array $property, mixed $value)
	{
		$this->property = $property;
		$this->value = $value;
	}

	public function toArray(): array
	{
		return [
			'property' => $this->property,
			'value' => $this->value,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
