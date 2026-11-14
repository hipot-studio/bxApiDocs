<?php

namespace Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityType;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\PreselectedActivitiesInterface;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\StrategyBase;

class CallRecordingStrategy extends StrategyBase implements PreselectedActivitiesInterface
{
	public function getType(): ActivityType
	{
		return ActivityType::CALL_RECORDING_TRANSCRIPTS;
	}

	public function collect(int $entityId, int $limit): array
	{
		$query = $this
			->queryBuilder
			->buildActivityQuery($this->entityTypeId, $entityId, Call::getId(), $limit)
		;

		$activityIds = $query
			->setSelect(['ID'])
			->whereNotNull('DESCRIPTION')
			->fetchCollection()
			?->getIdList()
		;

		return $this->collectByActivityIds($activityIds ?? []);
	}

	/**
	 * The transcripts are taken out of the repository of the AI jobs and not out of the activities, so
	 * the calls are enough to answer and their rows are never read here.
	 */
	public function collectByActivityIds(array $activityIds): array
	{
		if (empty($activityIds))
		{
			return [];
		}

		$jobRepository = JobRepository::getInstance();
		$transcripts = [];
		foreach ($activityIds as $activityId)
		{
			$transcription = $jobRepository
				->getTranscribeCallRecordingResultByActivity($activityId)
				?->getPayload()
				?->transcription
			;

			if ($transcription)
			{
				$normalizedText = $this
					->textNormalizer
					->normalize($transcription, $this->getType())
				;
				if ($normalizedText !== null)
				{
					$transcripts[] = $normalizedText;
				}
			}
		}

		return $this->filterValidData($transcripts);
	}
}
