<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;

class DocumentAccessService
{
	public function canCreate(int $userId, DocumentDescription $documentType): bool
	{
		return \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$userId,
			$documentType->toBizprocComplexType(),
		);
	}
}