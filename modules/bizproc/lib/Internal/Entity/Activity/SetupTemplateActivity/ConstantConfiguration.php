<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final readonly class ConstantConfiguration implements JsonSerializable, Arrayable
{
	public function __construct(
		private string $title,
		private string $type,
		private array $options,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'title' => $this->title,
			'type' => $this->type,
			'options' => $this->options,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
