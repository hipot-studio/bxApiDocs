<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum\Scenario as BizprocScenario;
use Bitrix\Bizproc\Starter\Result\StartResult;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class CallAutostartLaunchService
{
	public function launch(
		int $activityId,
		string $scenario,
		?int $userId,
		int $storageTypeId,
		int $storageElementId,
		bool $shouldStartCallAssessment,
		bool $fillFieldsEntityAllowed = true,
	): Result
	{
		$transcriptionResult = $this->launchTranscription(
			$activityId,
			$scenario,
			$userId,
			$storageTypeId,
			$storageElementId,
			['fillFieldsEntityAllowed' => $fillFieldsEntityAllowed],
		);
		if (!$transcriptionResult->isSuccess() || !$shouldStartCallAssessment)
		{
			return $transcriptionResult;
		}

		$starterResult = $this->startCallAssessmentTrigger($activityId, $userId ?? 0);
		if (!$starterResult->isSuccess())
		{
			$starterResult->addError(ErrorCode::getCallAssessmentTriggerLaunchFailedError());

			return $starterResult;
		}

		if (
			$starterResult instanceof StartResult
			&& !$starterResult->isTriggerApplied()
		)
		{
			$starterResult->addError(ErrorCode::getCallAssessmentTriggerLaunchFailedError());
		}

		return $starterResult;
	}

	protected function launchTranscription(
		int $activityId,
		string $scenario,
		?int $userId,
		int $storageTypeId,
		int $storageElementId,
		array $contextExtra,
	): Result
	{
		return AIManager::launchCallRecordingTranscription(
			$activityId,
			$scenario,
			$userId,
			$storageTypeId,
			$storageElementId,
			false,
			null,
			$contextExtra,
		);
	}

	protected function startCallAssessmentTrigger(int $activityId, int $userId): Result
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(ErrorCode::getAIEngineNotFoundError());
		}

		$startResult = Starter::getByScenario(BizprocScenario::onEvent)
			->setContext(new ContextDto('crm'))
			->addEvent('CrmCallAssessmentTrigger', [], [
				'ActivityId' => $activityId,
				// zero means automatic selection: the candidates admissible for this call are resolved by
				// the assessment activity itself, so the choice between them stays with the script selector
				'AssessmentSettingsId' => 0,
				'UserId' => $userId,
			])
			->start()
		;

		return $startResult;
	}
}
