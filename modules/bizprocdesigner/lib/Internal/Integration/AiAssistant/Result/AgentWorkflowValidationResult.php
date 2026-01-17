<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\Main\Result;

class AgentWorkflowValidationResult extends Result
{
	public function __construct(
		public readonly AgentConnectionCollection $connections,
		public readonly AgentBlockCollection $blocks,
	)
	{
		parent::__construct();
	}
}