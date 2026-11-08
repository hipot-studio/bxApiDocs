<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

abstract class BaseSchemaBuilder
{
	public const FORMATTING_NOTE =
		'Generate in markdown.'
		. ' If the user supplied formatting (markdown or BBCode), preserve it verbatim —'
		. ' do not rewrite BBCode as markdown.'
		. ' Add markdown structure only when it clearly improves readability.'
	;

	public const DATE_TIME_NOTE =
		" Use ISO 8601 (e.g. '2026-06-22T15:30:00')."
		. " Without a timezone the value is interpreted in the user's current timezone;"
		. " append an offset (e.g. '+03:00' or 'Z') only to set an explicit timezone."
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
