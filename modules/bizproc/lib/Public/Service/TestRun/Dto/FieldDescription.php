<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\TestRun\Dto;

final class FieldDescription
{
	/**
	 * @param array $property Normalized presentation of the field, `FieldType::normalizeProperty`.
	 * @param mixed $lastValue Value of the last real firing of the node, null when there was none.
	 * @param array $usedIn Nodes referring to the field, `[['blockId' => string, 'title' => string]]`.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $title,
		public readonly array $property,
		public readonly mixed $lastValue = null,
		public readonly array $usedIn = [],
	)
	{
	}

	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'title' => $this->title,
			'property' => $this->property,
			'lastValue' => $this->lastValue,
			'usedIn' => $this->usedIn,
		];
	}
}
