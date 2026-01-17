<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Template\Params;

class TemplateHistoryCountParams
{
	public function __construct(
		public readonly int $templateId,
		public readonly int $userId,
		public readonly bool $checkAccess = true,
	)
	{

	}
}
