<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;

final class WorkflowTemplateIdentifier
{
	public function __construct(
		public readonly DocumentDescription $documentDescription,
		public readonly ?int $templateId,
		public readonly ?int $draftId = null,
	) {}
}