<?php

namespace Bitrix\Bizproc\Public\Provider\WorkflowTemplate;

use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;

class AiAgentProvider
{
	public function __construct(
		private readonly AiAgentRepository $aiAgentRepository,
	) {}

	/**
	 * @param list<int> $ids
	 *
	 * @return list<int>
	 */
	public function getOnlyExistAndAllowedToDeleteTemplateIds(
		array $ids,
		int $userIdDeleteBy,
		bool $isUserAdmin=false,
	): array
	{
		return $this->aiAgentRepository->getOnlyExistAndAllowedToDeleteTemplateIds($ids, $isUserAdmin, $userIdDeleteBy);
	}

	/**
	 * Server-side ownership guard for managing a launched AI-agent (restart/fill).
	 *
	 * Returns true only when $templateId is a launched AI-agent copy (TYPE=Nodes, SYSTEM_CODE IS NULL)
	 * and the user is its owner (ACTIVATED_BY) or an admin. Single source of truth used by the
	 * controllers (start/fill) to forbid managing an agent owned by another user.
	 *
	 * @param int $templateId Template id.
	 * @param int $userId Acting user id (taken from server-side state, not from the request).
	 * @param bool $isUserAdmin Whether the user is an admin (bypasses the owner check).
	 * @param bool $requireStarted When true, additionally requires the template to be already started (ACTIVATED_AT set).
	 */
	public function canManageLaunchedTemplate(
		int $templateId,
		int $userId,
		bool $isUserAdmin,
		bool $requireStarted = false,
	): bool
	{
		return $this->aiAgentRepository->canManageLaunchedTemplate(
			$templateId,
			$userId,
			$isUserAdmin,
			$requireStarted,
		);
	}

	/**
	 * AI-agent template (system or user-created) that can be copied & started from the grid:
	 * a Nodes template from the AI_AGENT section that is not a launched copy. Used by
	 * copyAndStartAction both as the access predicate and as the source of SYSTEM_CODE for
	 * resolving the copy source (null SYSTEM_CODE = a user-created agent copied as
	 * CreateSource::User). See AiAgentRepository::findCopyableAiAgentTemplate().
	 *
	 * @param int $templateId Template id to inspect.
	 *
	 * @return array{SYSTEM_CODE: ?string}|null null when the template is not a valid copy source.
	 */
	public function findCopyableAiAgentTemplate(int $templateId): ?array
	{
		return $this->aiAgentRepository->findCopyableAiAgentTemplate($templateId);
	}

	/**
	 * SYSTEM_CODE of a system AI-agent template (TYPE=Nodes with SYSTEM_CODE set), or null when it is
	 * not one: a launched copy (SYSTEM_CODE IS NULL), a system template of another type or a missing
	 * template.
	 *
	 * @param int $templateId Template id to inspect.
	 */
	public function getSystemAiAgentCode(int $templateId): ?string
	{
		return $this->aiAgentRepository->getSystemAiAgentCode($templateId);
	}
}
