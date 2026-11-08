<?php

namespace Bitrix\Bizproc\Public\Provider\WorkflowTemplate;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Main\Result;
use CBPWorkflowTemplateUser;

class AiAgentProvider
{
	/**
	 * Stable, tariff-independent error code emitted when the current user may not launch an
	 * already launched AI-agent copy owned by another user. Single source of truth for consumers.
	 */
	public const LAUNCH_ACCESS_DENIED_CODE = 'AI_AGENT_START_ACCESS_DENIED';

	public function __construct(
		private readonly AiAgentRepository $aiAgentRepository,
	) {}

	/**
	 * Pre-check whether the current user may launch a system AI-agent before the setup wizard opens.
	 *
	 * A null id (no copy yet) always passes (first launch). For an existing copy the launch is allowed
	 * only to its owner or an admin (via canManageLaunchedTemplate), otherwise a Result with the stable
	 * LAUNCH_ACCESS_DENIED_CODE is returned. User id and admin flag are resolved server-side
	 * (CBPWorkflowTemplateUser); the error exposes no identifiers in its message or customData.
	 *
	 * @param int|null $launchedTemplateId Id of the already found launched copy, or null when none exists.
	 */
	public function checkLaunchAccess(?int $launchedTemplateId = null): Result
	{
		$result = new Result();

		if ($launchedTemplateId === null)
		{
			return $result;
		}

		$currentUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
		$canManage = $this->canManageLaunchedTemplate(
			$launchedTemplateId,
			(int)$currentUser->getId(),
			$currentUser->isAdmin(),
		);

		if (!$canManage)
		{
			$result->addError(
				ErrorMessage::START_ACCESS_DENIED->getError([], self::LAUNCH_ACCESS_DENIED_CODE),
			);
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 *
	 * @return list<int>
	 */
	public function getOnlyExistAndAllowedToDeleteTemplateIds(
		array $ids,
		int $userIdDeleteBy,
		bool $isUserAdmin = false,
		bool $ignoreOwner = false,
	): array
	{
		return $this->aiAgentRepository->getOnlyExistAndAllowedToDeleteTemplateIds(
			$ids,
			$isUserAdmin,
			$userIdDeleteBy,
			$ignoreOwner,
		);
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
	 * Server-side guard for handing a launched AI-agent over to another employee.
	 *
	 * Returns true only when $templateId is a started launched AI-agent copy (TYPE=Nodes, SYSTEM_CODE
	 * IS NULL, ACTIVATED_AT set) and the user is its owner (ACTIVATED_BY), an admin or a holder of the
	 * right to edit this template. Single source of truth for both consumers of the check: the transfer
	 * action of the controller, which asks it first to answer with a precise error code, and the grid
	 * action that offers the menu item.
	 *
	 * The predicate says nothing about the recipient: the transfer operation checks the new owner itself.
	 *
	 * @param int $templateId Template id.
	 * @param int $userId Acting user id (taken from server-side state, not from the request).
	 * @param bool $isUserAdmin Whether the user is an admin (bypasses the owner check).
	 */
	public function canTransferOwnership(int $templateId, int $userId, bool $isUserAdmin): bool
	{
		return $this->aiAgentRepository->canTransferOwnership($templateId, $userId, $isUserAdmin);
	}

	/**
	 * Warms canTransferOwnership() for a caller holding a whole page of ids (the AI-agents grid), turning
	 * the one to three queries per row into two per page. Published here because this facade is a single
	 * instance per request, while the grid asks the predicate from an action built anew for every row.
	 *
	 * An optimization, never a gate: the predicate stays correct without it, only more expensive.
	 *
	 * @param array<int, int|string> $templateIds
	 * @param int $userId Acting user the checks will be asked about (taken from server-side state).
	 */
	public function warmupTransferRights(array $templateIds, int $userId): void
	{
		$this->aiAgentRepository->warmupTransferRights($templateIds, $userId);
	}

	/**
	 * Actuality predicate for a restart target (API-01 ERR-002). True only when $templateId is
	 * still a started launched AI-agent copy (TYPE=Nodes, SYSTEM_CODE IS NULL, ACTIVATED_AT set),
	 * regardless of owner. The restart action uses it to tell a stale template (deleted, or changed
	 * so it is no longer a restartable launched copy) from a genuine access denial: staleness is
	 * existence only, ownership is the separate canManageLaunchedTemplate() check. Bypasses the
	 * owner filter (isUserAdmin=true) precisely because it must not fold ownership into staleness.
	 */
	public function isRestartableLaunchedTemplate(int $templateId): bool
	{
		return $this->canManageLaunchedTemplate($templateId, 0, isUserAdmin: true, requireStarted: true);
	}

	/**
	 * Owner (ACTIVATED_BY) of a launched copy; null when $templateId is not one or carries no owner.
	 * Published for the second way changeOwner data can be out of date: the copy is still live, but its
	 * owner is no longer the one the caller saw, and that refusal reads as ERR-002 rather than a denial
	 * of the right.
	 */
	public function getLaunchedCopyOwner(int $templateId): ?int
	{
		return $this->aiAgentRepository->getActivatedBy($templateId);
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
