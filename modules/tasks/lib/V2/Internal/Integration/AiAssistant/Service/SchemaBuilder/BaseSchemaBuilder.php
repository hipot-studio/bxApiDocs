<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

abstract class BaseSchemaBuilder
{
	public const DATE_FORMAT = 'Y/m/d H:i';

	public function build(?string $action): array
	{
		return [
			'type' => 'object',
			'properties' => $this->getProperties($action),
			'required' => $this->getRequiredFields($action),
			'additionalProperties' => false,
		];
	}

	abstract protected function getProperties(?string $action): array;

	abstract protected function getRequiredFields(?string $action): array;
}
