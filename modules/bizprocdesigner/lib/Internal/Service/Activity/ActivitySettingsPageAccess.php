<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\Bizproc\Public\Service\AiAgent\SystemTemplateViewService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\BizprocDesigner\Internal\Service\SystemTemplateView\SystemTemplateViewServiceProvider;
use Bitrix\Main\DI\ServiceLocator;

/**
 * The gate of the settings page of the old designer, `bizprocdesigner/admin/bizproc_activity_settings.php`.
 *
 * A request carrying `viewTemplateId` comes from the read-only view of a system AI-agent template: it is
 * admitted by the admission predicate of the view instead of the right to author workflows on the document
 * type, but only for the document type of that very template - otherwise the ticket would open the forms,
 * and the document metadata they carry, of any document type. Such a request never saves: the page answers
 * its save branch with a refusal.
 */
class ActivitySettingsPageAccess
{
	private const VIEW_TEMPLATE_ID = 'viewTemplateId';

	private ?SystemTemplateViewService $viewService;

	public function __construct(?SystemTemplateViewService $viewService = null)
	{
		$this->viewService = $viewService;
	}

	public function isViewRequest(array $post): bool
	{
		return $this->readViewTemplateId($post) > 0;
	}

	public function canOpen(array $post, array $documentType, int $userId): bool
	{
		if (!$this->isViewRequest($post))
		{
			return $this->canCreateWorkflow($userId, $documentType);
		}

		$viewService = $this->viewService();
		if ($viewService === null)
		{
			return false;
		}

		$result = $viewService->resolve($this->readViewTemplateId($post), $userId);
		if (!$result->isSuccess())
		{
			return false;
		}

		return $this->isDocumentTypeOf(
			(int)$result->getData()[SystemTemplateViewService::SYSTEM_TEMPLATE_ID],
			$documentType,
		);
	}

	/** A view request is refused any save, whatever it was admitted by. */
	public function canSave(array $post): bool
	{
		return !$this->isViewRequest($post);
	}

	protected function canCreateWorkflow(int $userId, array $documentType): bool
	{
		return (bool)\CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$userId,
			$documentType,
		);
	}

	private function isDocumentTypeOf(int $templateId, array $documentType): bool
	{
		$row = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->setSelect(['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
			->setLimit(1)
			->fetch()
		;
		if (!is_array($row))
		{
			return false;
		}

		return [(string)$row['MODULE_ID'], (string)$row['ENTITY'], (string)$row['DOCUMENT_TYPE']]
			=== array_map(strval(...), array_values($documentType))
		;
	}

	private function readViewTemplateId(array $post): int
	{
		$value = $post[self::VIEW_TEMPLATE_ID] ?? null;

		return is_scalar($value) ? max(0, (int)$value) : 0;
	}

	private function viewService(): ?SystemTemplateViewService
	{
		return $this->viewService ??= ServiceLocator::getInstance()->get(SystemTemplateViewServiceProvider::class)->get();
	}
}
