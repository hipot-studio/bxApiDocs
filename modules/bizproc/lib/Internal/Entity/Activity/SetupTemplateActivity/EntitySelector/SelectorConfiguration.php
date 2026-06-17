<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\EntitySelector;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final readonly class SelectorConfiguration implements Arrayable, JsonSerializable
{
	public function __construct(
		public string $id,
		public string $title,
		public array $dialogOptions,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'dialogOptions' => $this->dialogOptions,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
