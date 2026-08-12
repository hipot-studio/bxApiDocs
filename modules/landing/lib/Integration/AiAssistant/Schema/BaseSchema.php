<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema;

abstract class BaseSchema
{
	final public function build(): array
	{
		return [
			'type' => 'object',
			'properties' => $this->getProperties(),
			'required' => $this->getRequiredFields(),
			'additionalProperties' => false,
		];
	}

	abstract protected function getProperties(): array;

	protected function getRequiredFields(): array
	{
		return [];
	}
}
