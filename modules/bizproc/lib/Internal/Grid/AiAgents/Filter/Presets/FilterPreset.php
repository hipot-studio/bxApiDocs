<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Filter\Presets;

abstract class FilterPreset
{
	abstract public function getId(): string;

	abstract public function getName(): string;

	abstract public function getFilterFields(): array;

	abstract public function isDefault(): bool;

	public function toArray(array $defaultFields = []): array
	{
		return [
			'name' => $this->getName(),
			'default' => $this->isDefault(),
			'fields' => array_merge($this->getFilterFields(), $defaultFields),
		];
	}
}
