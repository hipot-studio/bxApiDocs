<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\AgentEntryVerdict;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\AgentEntryAuthorizationResult;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

/**
 * The single admission boundary of the agent contour: whoever opens a session with it passes here first.
 *
 * Two entries share it and must not drift apart - the button of the editor
 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Diagram::connectAgentAction()}) and the REST
 * entry an external caller authorizes with its own key
 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\Agent\Session}). Before this service the
 * check lived private in the editor controller, so the REST entry would have had to copy it - and a copy
 * of an access check is the kind that stops matching its original quietly.
 *
 * It holds no access logic of its own beyond the order: the feature gate is {@see Feature}, the rights are
 * {@see OperationAuthorizationGuard}, which in turn delegates to {@see DocumentAccessService}. What this
 * class owns is the ORDER of the three questions and the four-valued answer, and both are load-bearing.
 * The feature gate stands first so that a portal with the feature off never tells a caller whether a
 * template id exists; the lookup stands second so that a refusal by rights is only ever pronounced about a
 * template that was actually found.
 */
final class AgentEntryAuthorizationService
{
	private readonly OperationAuthorizationGuard $authorizationGuard;
	private readonly DocumentAccessService $documentAccessService;
	private readonly TemplateAccessService $templateAccessService;

	public function __construct(
		?OperationAuthorizationGuard $authorizationGuard = null,
		?DocumentAccessService $documentAccessService = null,
		?TemplateAccessService $templateAccessService = null,
	)
	{
		$this->authorizationGuard = $authorizationGuard ?? new OperationAuthorizationGuard();
		$this->documentAccessService = $documentAccessService ?? new DocumentAccessService();
		$this->templateAccessService = $templateAccessService ?? new TemplateAccessService();
	}

	/**
	 * Admission to an existing template: the feature, then the template, then the rights over it.
	 *
	 * The identifier travels back with the verdict because every caller needs it right after and resolving
	 * it twice would read the row twice.
	 *
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function authorizeTemplate(int $userId, int $templateId): AgentEntryAuthorizationResult
	{
		if (!$this->isFeatureAccessible())
		{
			return AgentEntryAuthorizationResult::of(AgentEntryVerdict::FeatureUnavailable);
		}

		$identifier = Container::getAgentWorkflowResolverService()->resolveByTemplateId($templateId, $userId);
		if ($identifier === null)
		{
			return AgentEntryAuthorizationResult::of(AgentEntryVerdict::TemplateNotFound);
		}

		$verdict = $this->authorizationGuard->authorize(
			$userId,
			$identifier->documentDescription,
			$templateId,
			UserAdminResolver::isAdmin($userId),
		);

		return $verdict->allowed
			? AgentEntryAuthorizationResult::of(AgentEntryVerdict::Allowed, $identifier)
			: AgentEntryAuthorizationResult::of(AgentEntryVerdict::AccessDenied);
	}

	/**
	 * Admission to a document type rather than to a template - the shape creation needs, where the template
	 * whose rights would be judged does not exist yet.
	 *
	 * The rights question is therefore the plain CreateWorkflow of the type
	 * ({@see DocumentAccessService::canCreate()}), never canManageDocument: the latter answers about an
	 * existing launched copy, and passing it a zero id would ask about a template that is not there.
	 *
	 * Two rights, not one, because the two answer about different things and the entry may be no wider
	 * than the editor. The legacy operation answers whether workflows may be authored on THIS document
	 * type at all; {@see TemplateAccessService::canCreate()} is the scope-less CREATE toggler of the
	 * Nodes matrix - the very right the editor asks before it creates a template
	 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Diagram::publishAction()}), and what
	 * this action always creates is a Nodes template. The two matrices are administered apart and do
	 * diverge, so asking only the first lets a user the editor refuses create through the entry.
	 *
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	public function authorizeDocumentType(int $userId, DocumentDescription $documentType): AgentEntryVerdict
	{
		if (!$this->isFeatureAccessible())
		{
			return AgentEntryVerdict::FeatureUnavailable;
		}

		$allowed = $this->documentAccessService->canCreate($userId, $documentType)
			&& $this->templateAccessService->canCreate($userId, $documentType->toBizprocComplexType())
		;

		return $allowed ? AgentEntryVerdict::Allowed : AgentEntryVerdict::AccessDenied;
	}

	/**
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	private function isFeatureAccessible(): bool
	{
		return Feature::instance()->isExternalAiAgentAccessible();
	}
}
