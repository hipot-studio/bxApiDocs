<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

class PresetValue
{
	public function __construct(
		public readonly int $value,
		public readonly string $label,
	) {}

	public function toArray(): array
	{
		return [
			'value' => $this->value,
			'label' => $this->label,
		];
	}
}
