<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\ActivityControllerBuilder;

use Bitrix\Main\Type\Contract\Arrayable;

class ActivityControlDto implements Arrayable, \JsonSerializable
{
	private array $property;
	private mixed $value;
	private array $meta;

	public function __construct(array $property, mixed $value, array $meta = [])
	{
		$this->property = $property;
		$this->value = $value;
		$this->meta = $meta;
	}

	public function toArray(): array
	{
		$result = [
			'property' => $this->property,
			'value' => $this->value,
		];

		if ($this->meta !== [])
		{
			$result['meta'] = $this->meta;
		}

		return $result;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
