<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Mixins;

use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Internal\Factory\Workflow\TriggerStageWorkflowFactory;
use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;

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

		$searcher = new Searcher();
		if ($searcher->isActivityExists($activityName) && \CBPRuntime::getRuntime()->includeActivityFile($activityName))
		{
			$activity = \CBPActivity::createInstance($activityName, '');
			if ($activity && method_exists($activity, 'checkApplyRules'))
			{
				$activity->initializeFromArray($rules['Properties']);
				$stubWorkflow = (new TriggerStageWorkflowFactory())->create($templateId, $documentId);
				$activity->setWorkflow($stubWorkflow);
				unset($rules['Properties']);
				$result = $activity->checkApplyRules($rules, new TriggerParameters($parameters));
			}
		}

		return $result ?: Result::createError(new Error('trigger not exist')); // todo: Loc
	}
}
