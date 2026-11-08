<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Dto;

final readonly class RelationAutofillResult
{
	/**
	 * @param array<string, string> $autofillMap
	 * @param list<string> $autofillRequiredFields Fields the relation package declares required (see the matcher).
	 * @param list<array{fieldId: string, code: string, sourceBlockIds: list<string>}> $autofillConflicts
	 */
	public function __construct(
		public array $autofillMap = [],
		public array $autofillRequiredFields = [],
		public array $autofillConflicts = [],
	)
	{
	}
}
