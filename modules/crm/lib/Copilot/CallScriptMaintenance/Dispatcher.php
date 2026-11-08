<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptMaintenance;

use Bitrix\Crm\Agent\Copilot\CallScriptMaintenanceAgent;
use Bitrix\Crm\Copilot\AiCallScriptSelection\Entity\AiCallScriptSelectionTable;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\GenerateCallCriteria;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\SystemUser;
use Bitrix\Crm\Traits\Singleton;
use CCrmOwnerType;

final class Dispatcher
{
	use Singleton;

	/** @deprecated Use getSystemUserId() instead. */
	public const SYSTEM_USER_ID = 1;

	public const SYNTHETIC_TARGET_ID = 1;

	public static function getSystemUserId(): int
	{
		return SystemUser::getDefaultAuthorId();
	}

	public function markGrouped(array $selectionIds): void
	{
		AiCallScriptSelectionTable::markGroupedByIds($selectionIds);
	}

	public function markEnriched(array $selectionIds): void
	{
		AiCallScriptSelectionTable::markEnrichedByIds($selectionIds);
	}

	public function launchCreateCallAssessmentJob(array $transcripts, ?int $parentJobId = null): int
	{
		$target = new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, self::SYNTHETIC_TARGET_ID);
		$result = (new GenerateCallCriteria($target, self::getSystemUserId(), $parentJobId))
			->setDialogues($transcripts)
			->setCreateMode(true)
			->setIsManualLaunch(false)
			->launch()
		;

		$jobId = (int)($result->getJobId() ?? 0);
		if ($jobId <= 0)
		{
			AIManager::logger()->error(
				'{date}: {class}: create_call_assessment job launch failed',
				['class' => self::class],
			);
		}

		return $jobId;
	}

	public function launchEnrichCallAssessmentJob(int $callAssessmentId, array $transcripts): int
	{
		$target = new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, $callAssessmentId);
		$result = (new GenerateCallCriteria($target, self::getSystemUserId()))
			->setDialogues($transcripts)
			->setIsManualLaunch(false)
			->launch()
		;

		$jobId = (int)($result->getJobId() ?? 0);
		if ($jobId <= 0)
		{
			AIManager::logger()->error(
				'{date}: {class}: enrich_call_assessment job launch failed (call assessment {callAssessment})',
				['class' => self::class, 'callAssessment' => $callAssessmentId],
			);
		}

		return $jobId;
	}

	public function loadDialogues(array $activityIds): array
	{
		$result = [];
		$jobRepo = JobRepository::getInstance();

		foreach ($activityIds as $activityId)
		{
			$activityId = (int)$activityId;
			if ($activityId <= 0)
			{
				continue;
			}

			$transcribeResult = $jobRepo->getTranscribeCallRecordingResultByActivity($activityId);
			if ($transcribeResult === null || !$transcribeResult->isSuccess())
			{
				continue;
			}

			$payload = $transcribeResult->getPayload();
			$transcript = is_object($payload) && property_exists($payload, 'transcription')
				? (string)$payload->transcription
				: '';

			if ($transcript === '')
			{
				continue;
			}

			$result[] = ['id' => $activityId, 'transcript' => $transcript];
		}

		return $result;
	}

	public function pingAgent(): void
	{
		CallScriptMaintenanceAgent::pingAgent();
	}
}
