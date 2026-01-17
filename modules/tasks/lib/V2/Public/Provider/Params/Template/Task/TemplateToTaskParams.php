<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template\Task;

class TemplateToTaskParams
{
	public function __construct(
		public readonly int $userId,
		public readonly int $templateId,
	)
	{

	}
}
