<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result;

use Bitrix\Main\Result;

class TemplateCreatedResult extends Result
{
	public function __construct(
		public readonly int $templateId,
	)
	{
		parent::__construct();
	}
}