<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Visibility\HiddenAiAgentsRegistry;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Main\Result;

/**
 * The single predicate "this template is visible to the user and allowed to be launched manually"
 * (PRED-01), shared by the pre-flight check and by the copy & start action so the two paths cannot
 * drift apart.
 *
 * A launchable template is a copy source - a Nodes template of the AI_AGENT section that has not
 * been activated yet - whose system code is not hidden by HiddenAiAgentsRegistry. The resolved
 * system code is returned in the result data, so consumers never need a second lookup.
 *
 * Admission then splits by system code. A system template of the catalogue stays a product feature:
 * copying it is how an agent is activated, and no role ACL takes part. A user-created agent is an
 * ordinary designer template, so copying it asks the template ACL for the write right.
 */
final class LaunchableTemplateResolver
{
	/** Result data key carrying the system code, null for a user-created template from the designer. */
	public const SYSTEM_CODE = 'systemCode';

	/**
	 * The only error code the predicate emits. Missing, already activated and hidden templates are
	 * deliberately indistinguishable: telling them apart would turn any consumer into an oracle
	 * over template ids and would leak the system code of a hidden agent.
	 */
	public const ERROR_CODE_ACCESS_DENIED = 'accessDenied';

	public function __construct(
		private readonly AiAgentRepository $aiAgentRepository,
		private readonly HiddenAiAgentsRegistry $hiddenAiAgents,
		private readonly TemplateAccessService $templateAccessService,
	) {}

	/**
	 * @return Result Successful result carries SYSTEM_CODE: a non-empty string for a system
	 *   template, null for a user-created one. On failure it carries a single accessDenied error.
	 */
	public function resolve(int $templateId): Result
	{
		if ($templateId <= 0)
		{
			return $this->denyAccess();
		}

		$template = $this->aiAgentRepository->findCopyableAiAgentTemplate($templateId);
		if ($template === null)
		{
			return $this->denyAccess();
		}

		$systemCode = $template['SYSTEM_CODE'] ?? null;
		if ($systemCode !== null && in_array($systemCode, $this->hiddenAiAgents->getHiddenSystemCodes(), true))
		{
			return $this->denyAccess();
		}

		// canEdit, not authorship: a colleague who built an agent may let others copy it. The denial
		// keeps the single accessDenied code, so a foreign agent stays indistinguishable from a
		// missing one.
		if ($systemCode === null && !$this->templateAccessService->canEdit($templateId))
		{
			return $this->denyAccess();
		}

		return (new Result())->setData([self::SYSTEM_CODE => $systemCode]);
	}

	private function denyAccess(): Result
	{
		$result = new Result();
		$result->addError(ErrorMessage::ACCESS_DENIED->getError([], self::ERROR_CODE_ACCESS_DENIED));

		return $result;
	}
}
