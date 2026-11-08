<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Bizproc;

use Bitrix\Crm\Integration\BizProc\Document\Dynamic;
use Bitrix\Main\Loader;

/**
 * The single point CRM V2 asks the business process module about workflow templates.
 *
 * The module is an optional dependency of a portal: its absence is answered with a refusal and not
 * with an error.
 *
 * @internal
 */
class WorkflowTemplateGateway
{
	/**
	 * The module is installed and the engine is licensed - the pair the designer page itself asks
	 * for before it renders.
	 */
	public function isTemplateDesignerAvailable(): bool
	{
		return $this->isModuleAvailable() && $this->isEngineFeatureEnabled();
	}

	public function canCreateTemplateForEntityType(int $entityTypeId, int $userId): bool
	{
		if (!$this->isModuleAvailable())
		{
			return false;
		}

		return $this->canCreateWorkflowForDocumentType(self::documentType($entityTypeId), $userId);
	}

	protected function isModuleAvailable(): bool
	{
		return Loader::includeModule('bizproc');
	}

	protected function isEngineFeatureEnabled(): bool
	{
		return \CBPRuntime::isFeatureEnabled();
	}

	/**
	 * @param list<string> $documentType the module id, the document class and the document type
	 */
	protected function canCreateWorkflowForDocumentType(array $documentType, int $userId): bool
	{
		// the module answers an untyped legacy value, so the boolean is settled here and not outside
		return (bool)\CBPDocument::canUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$userId,
			$documentType,
		);
	}

	/**
	 * @return list<string>
	 */
	private static function documentType(int $entityTypeId): array
	{
		return ['crm', Dynamic::class, \CCrmOwnerType::ResolveName($entityTypeId)];
	}
}
