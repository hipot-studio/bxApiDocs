<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Internal\Factory\Workflow\TriggerStageWorkflowFactory;

/**
 * The condition of a node, decided on the record the node names.
 *
 * Both moments that condition is evaluated at go through here - the check before the start
 * ({@see \Bitrix\Bizproc\Activity\Mixins\ApplyRulesChecker::checkApplyRules()}) and the execution of the node
 * inside the process ({@see \CBPWorkflow::isNodeConditionMet()}). Built apart, the two answered differently on
 * one and the same condition: the document of the process is a pseudo-document carrying no field of the record,
 * so a node accepted before the start was skipped at execution and published no return at all.
 */
final class NodeConditionEvaluator
{
	/**
	 * @param TriggerParameters $parameters Parameters of the event the node answers with its record out of.
	 *     Built by the caller before this call: they are read off the root activity, and the root is what the
	 *     stub below replaces ({@see \Bitrix\Bizproc\Activity\BaseTrigger::getEventData()}).
	 * @param int $templateId Template of the node: the stub stays bound to it, so template constants inside the
	 *     condition keep resolving.
	 * @param array $processDocumentId Document of the process, kept for every node that names nothing else.
	 */
	public function isMet(
		\CBPMixedCondition $condition,
		\CBPActivity $activity,
		TriggerParameters $parameters,
		int $templateId,
		array $processDocumentId,
	): bool
	{
		$documentId = $this->resolveDocumentId($activity, $parameters);
		if ($documentId === null || \CBPHelper::isEqualDocument($documentId, $processDocumentId))
		{
			return $condition->evaluate($activity);
		}

		// CBPMixedCondition reads the document through the workflow the activity is bound to and from nowhere
		// else, so only the stub can carry the record. The restore is symmetric on purpose: setWorkflow()
		// reaches the children of the node as well ({@see \CBPActivity::setWorkflow()}).
		$original = $activity->workflow;
		$activity->setWorkflow(
			(new TriggerStageWorkflowFactory())->create($templateId, $documentId),
		);

		try
		{
			return $condition->evaluate($activity);
		}
		finally
		{
			$activity->setWorkflow($original);
		}
	}

	/**
	 * Record the condition is to be decided on, as the node itself names it
	 * ({@see ComplexActivityService::declaresConditionDocumentChannel()}).
	 * Null for a node that declares no channel, names nothing, or answers with something that is not a complex
	 * identifier - an invalid answer is treated as no answer, and no failure of a node may take down the check
	 * before the start or the process the node runs in.
	 */
	private function resolveDocumentId(\CBPActivity $activity, TriggerParameters $parameters): ?array
	{
		if (!ComplexActivityService::declaresConditionDocumentChannel($activity))
		{
			return null;
		}

		try
		{
			$documentId = $activity->getConditionDocumentId($parameters);
		}
		catch (\Throwable)
		{
			return null;
		}

		return ComplexActivityService::isComplexDocumentType($documentId) ? array_values($documentId) : null;
	}

}
