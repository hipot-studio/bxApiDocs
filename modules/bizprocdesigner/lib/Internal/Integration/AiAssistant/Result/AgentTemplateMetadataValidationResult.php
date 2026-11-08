<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplateMetadata;
use Bitrix\Main\Result;

final class AgentTemplateMetadataValidationResult extends Result
{
	public function __construct(
		public readonly AgentTemplateMetadata $metadata = new AgentTemplateMetadata(),
	)
	{
		parent::__construct();
	}
}
