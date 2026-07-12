<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

abstract class BaseSchemaBuilder
{
	public const DATE_FORMAT = 'Y/m/d H:i';

	public const FORMATTING_NOTE =
		'Generate in markdown.'
		. ' If the user supplied formatting (markdown or BBCode), preserve it verbatim —'
		. ' do not rewrite BBCode as markdown.'
		. ' Add markdown structure only when it clearly improves readability.'
	;

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
