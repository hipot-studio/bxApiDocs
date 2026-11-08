<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\AiAgentProvider;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Service\TemplateEditAccess;
use Bitrix\Main\DI\ServiceLocator;

final readonly class DocumentAccessService
{
	private AiAgentProvider $aiAgentProvider;
	private TemplateEditAccess $templateEditAccess;

	public function __construct(
		?AiAgentProvider $aiAgentProvider = null,
		?TemplateEditAccess $templateEditAccess = null,
	)
	{
		$this->aiAgentProvider = $aiAgentProvider
			?? ServiceLocator::getInstance()->get(AiAgentProvider::class);
		$this->templateEditAccess = $templateEditAccess ?? new TemplateEditAccess();
	}

	public function canCreate(int $userId, DocumentDescription $documentType): bool
	{
		return \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$userId,
			$documentType->toBizprocComplexType(),
		);
	}

	/**
	 * Whether the user may read the elements of this document type, which is what reading its field schema
	 * asks for.
	 *
	 * Deliberately weaker than {@see self::canCreate()}: authoring a template of a type is a configuration
	 * right, while reading the codes of its fields is the right to see its items. For crm this lands on
	 * canReadItems() of the entity type, true for anyone with access to at least one of its funnels. The
	 * asymmetry is accepted on purpose (BRIEF § 6.2, § 15.11) - the stricter right would close the agent to
	 * everyone without administrative rights in crm, and what is disclosed is the structure of the entity and
	 * never the values of its records.
	 *
	 * Asked once per entity by whoever filters a field list by it ({@see RelatedDocumentFieldPolicy}): a check
	 * per field would run hundreds of identical permission lookups for one answer.
	 */
	public function canViewDocumentType(int $userId, DocumentDescription $documentType): bool
	{
		return \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::ViewWorkflow,
			$userId,
			$documentType->toBizprocComplexType(),
		);
	}

	/**
	 * Type-aware authoring guard for the AI-agent integration.
	 *
	 * Authoring on the WORKFLOW pseudo type is admin-only by design (fix #2: a non-admin could
	 * otherwise save a global collector template with a trigger and read other users' private data).
	 * That rule also denied the legitimate owner of their own running AI-agent copy, which this guard
	 * restores without weakening the boundary:
	 *  - an admin keeps full WORKFLOW authoring (the admin-only rule stays intact);
	 *  - a non-admin passes only as the owner of their own started AI-agent launched copy
	 *    (ACTIVATED_BY, requireStarted), which also closes the IDOR by a foreign templateId;
	 *  - any other non-admin (a global/foreign WORKFLOW template) stays denied.
	 *
	 * For real document types (CRM/tasks general designer) it stays the legacy CreateWorkflow check.
	 *
	 * @param int $templateId Launched copy id (only consulted for the WORKFLOW pseudo type).
	 * @param bool $isUserAdmin Whether the acting user is an admin (full bypass on the pseudo type).
	 */
	public function canManageDocument(
		int $userId,
		DocumentDescription $documentType,
		int $templateId,
		bool $isUserAdmin,
	): bool
	{
		if ($this->isWorkflowPseudoType($documentType))
		{
			if ($isUserAdmin)
			{
				return true;
			}

			return $this->aiAgentProvider->canManageLaunchedTemplate(
				$templateId,
				$userId,
				isUserAdmin: false,
				requireStarted: true,
			);
		}

		return $this->canCreate($userId, $documentType);
	}

	/**
	 * The template ACL on top of the document guard, for the paths of the external agent that address a
	 * template of the editor. The agent writes into the very draft a person is editing, and the graph of that
	 * draft travels back to whoever holds the lease of the template; both belong to the right to edit that
	 * template, which the wide CreateWorkflow right of the document type is not.
	 *
	 * The WORKFLOW pseudo type keeps the document guard alone: the ACL of a launched copy refuses its own
	 * legitimate owner, whom canManageDocument admits on purpose. A template that has no id yet has no ACL
	 * either — its creation is the boundary of canManageDocument.
	 */
	public function canEditTemplate(int $userId, DocumentDescription $documentType, int $templateId): bool
	{
		if ($templateId <= 0 || $this->isWorkflowPseudoType($documentType))
		{
			return true;
		}

		$tpl = $this->templateEditAccess->resolve($templateId);

		return $tpl !== null && $this->templateEditAccess->canEdit($tpl, $userId);
	}

	private function isWorkflowPseudoType(DocumentDescription $documentType): bool
	{
		return $documentType->toBizprocComplexType() === Workflow::getComplexType();
	}
}
