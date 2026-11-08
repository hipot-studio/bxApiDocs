<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\Tpl;

/**
 * The fork every server-side path of the editor applies to a loaded template row: a Nodes template belongs
 * to the template ACL end to end, everything else keeps the legacy document-type gate.
 *
 * The fork lives here and nowhere else because its copies drift apart silently: it is an access boundary,
 * and one of its callers decides who receives the whole graph of a draft in a pull event. Its equivalence
 * rests on the document type of a template always being a triple, which is a property of Tpl and not of the
 * rule — a builder that starts answering with an empty type would otherwise weaken the copies one by one.
 */
final class TemplateEditAccess
{
	private TemplateAccessService $accessService;

	public function __construct(?TemplateAccessService $accessService = null)
	{
		$this->accessService = $accessService ?? new TemplateAccessService();
	}

	/**
	 * The record the fork is applied to, read in the single shape every caller needs: a system template takes
	 * no part in the editor at all, and the gate asks about no more than these fields.
	 */
	public function resolve(int $templateId): ?Tpl
	{
		return WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->whereNull('SYSTEM_CODE')
			->setSelect(['ID', 'TYPE', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
			->setLimit(1)
			->exec()
			->fetchObject()
		;
	}

	public function canEdit(Tpl $tpl, int $userId): bool
	{
		return $tpl->getType() === WorkflowTemplateType::Nodes->value
			? $this->accessService->canEdit((int)$tpl->getId(), $userId, $tpl->getDocumentComplexType())
			: $this->canWriteLegacy($tpl, $userId)
		;
	}

	/**
	 * The legacy leg of the fork on its own: the paths that already know the template is not a Nodes one ask
	 * for it directly.
	 */
	public function canWriteLegacy(Tpl $tpl, int $userId): bool
	{
		$documentType = $tpl->getDocumentComplexType();

		return (bool)$documentType && \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$userId,
			$documentType,
		);
	}
}
