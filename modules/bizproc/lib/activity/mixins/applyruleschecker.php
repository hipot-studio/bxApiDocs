<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Mixins;

use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Internal\Factory\Workflow\TriggerStageWorkflowFactory;
use Bitrix\Bizproc\Internal\Service\Activity\NodeConditionEvaluator;
use Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Result;

trait ApplyRulesChecker
{
	public function checkApplyRules(
		string $activityName,
		array $rules,
		array $parameters,
		int $templateId,
		array $documentId,
	): Result
	{
		if (!$rules)
		{
			return Result::createOk();
		}

		$result = null;

		$searcher = Container::instance()->getActivitySearcherService();
		if ($searcher->isActivityExists($activityName) && \CBPRuntime::getRuntime()->includeActivityFile($activityName))
		{
			$activity = \CBPActivity::createInstance($activityName, '');
			if ($activity && method_exists($activity, 'checkApplyRules'))
			{
				$properties = $rules['Properties'] ?? [];
				$activity->initializeFromArray($properties);
				$stubWorkflow = (new TriggerStageWorkflowFactory())->create($templateId, $documentId);
				$activity->setWorkflow($stubWorkflow);
				unset($rules['Properties']);
				$triggerParameters = new TriggerParameters($parameters);
				$result = $activity->checkApplyRules($rules, $triggerParameters);

				if ($result->isSuccess())
				{
					$result = $this->checkNodeCondition(
						$properties,
						$activity,
						$result,
						$templateId,
						$documentId,
						$triggerParameters,
					);
				}
			}
		}

		return $result ?: Result::createError(new Error('trigger not exist')); // todo: Loc
	}

	/**
	 * The condition of a trigger served by the unified panel, in conjunction with the native rule check:
	 * reached only after the native check accepted the event, so document fields are not read for events
	 * the trigger does not take anyway. An empty condition means "start on every accepted event".
	 *
	 * @param \CBPActivity $activity The trigger instance the native check has just run on, bound to the
	 *     stub workflow the condition reads the document through.
	 * @param Result $nativeResult Verdict of the native check, returned unchanged when there is no condition.
	 * @param int $templateId Template of the trigger row: the stub of the condition stays bound to it, so
	 *     template constants inside the condition keep resolving.
	 * @param array $documentId Document of the process, the one the native check has just run on.
	 * @param TriggerParameters $parameters The parameters of the event already built for the native check.
	 */
	private function checkNodeCondition(
		array $properties,
		\CBPActivity $activity,
		Result $nativeResult,
		int $templateId,
		array $documentId,
		TriggerParameters $parameters,
	): Result
	{
		$condition = $properties[UnifiedPanelDescriptorProvider::CONDITION_PROPERTY] ?? [];
		if (!is_array($condition) || !$condition)
		{
			return $nativeResult;
		}

		// Fails closed: a portal missing the evaluator can check no condition at all, and starting the
		// process would ignore what the user configured.
		if (!\CBPRuntime::getRuntime()->includeActivityFile('MixedCondition'))
		{
			return Result::createError(new Error('trigger condition evaluator not exist')); // todo: Loc
		}

		// A node that named a record of its own has its condition decided on that record, here and at the
		// execution of the node alike ({@see NodeConditionEvaluator}). The stub of the native check above is
		// left alone.
		return (new NodeConditionEvaluator())->isMet(
			new \CBPMixedCondition($condition),
			$activity,
			$parameters,
			$templateId,
			$documentId,
		)
			? Result::createOk()
			: Result::createError(new Error('trigger condition not met')) // todo: Loc
		;
	}
}
