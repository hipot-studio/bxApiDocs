<?php

namespace Bitrix\Crm\Integration\AI;

use Bitrix\AI\Context;
use Bitrix\AI\Model\QueueTable as AIQueueTable;
use Bitrix\Crm\Integration\AI\Dto\AnalyzeCommunicationPayload;
use Bitrix\Crm\Integration\AI\Dto\FillItemFieldsFromCallTranscriptionPayload;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\FillRepeatSaleTipsPayload;
use Bitrix\Crm\Integration\AI\Dto\Scoring\ExtractScoringCriteriaPayload;
use Bitrix\Crm\Integration\AI\Dto\Scoring\ScoreCallV2Payload;
use Bitrix\Crm\Integration\AI\Dto\SummarizeCallTranscriptionPayload;
use Bitrix\Crm\Integration\AI\Dto\TranscribeCallRecordingPayload;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\EventManager;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;

class JobRepository
{
	use Singleton;

	private const OWNER_SCOPED_JOB_LOOKUP_LIMIT = 20;
	private const OWNER_SCOPED_JOB_SCAN_LIMIT = 200;

	/** @var Array<int, Result|null> */
	private array $transcribeCache = [];
	/** @var Array<string, Result|null> */
	private array $summarizeCache = [];
	/** @var Array<string, Result|null> */
	private array $fillCache = [];
	/** @var Array<int, Result|null> */
	private array $fillByIdCache = [];
	/** @var array<int|string, Result|null> */
	private array $callScoringCache = [];
	/** @var Array<int, Result|null> */
	private array $extractScoringCriteriaCache = [];
	/** @var Array<int, Result|null> */
	private array $fillRepeatSaleTips = [];
	/** @var Array<int, Result|null> */
	private array $analyzeCommunicationCache = [];
	/** @var Array<string, array> */
	private array $summarizeTranscriptionDataCache = [];
	/** @var Array<int, array|null> */
	private array $jobOwnerCache = [];

	private EventManager $ormEventManager;
	private array $eventKeys = [
		DataManager::EVENT_ON_AFTER_ADD => true,
		DataManager::EVENT_ON_AFTER_UPDATE => true,
		DataManager::EVENT_ON_AFTER_DELETE => true,
	];

	private function __construct()
	{
		$this->ormEventManager = EventManager::getInstance();

		foreach ($this->eventKeys as $eventName => $doesntMatter)
		{
			$this->eventKeys[$eventName] = $this->ormEventManager->addEventHandler(
				QueueTable::class,
				$eventName,
				[$this, 'cleanRuntimeCache'],
			);
		}
	}

	public function __destruct()
	{
		foreach ($this->eventKeys as $eventName => $eventKey)
		{
			if (is_numeric($eventKey))
			{
				$this->ormEventManager->removeEventHandler(
					QueueTable::class,
					$eventName,
					$eventKey,
				);
			}
		}
	}

	// region Payload result
	/**
	 * @param int $activityId
	 *
	 * @return Result<TranscribeCallRecordingPayload>|null
	 */
	public function getTranscribeCallRecordingResultByActivity(int $activityId): ?Result
	{
		if (array_key_exists($activityId, $this->transcribeCache))
		{
			return is_object($this->transcribeCache[$activityId])
				? clone $this->transcribeCache[$activityId]
				: null
			;
		}

		if ($activityId > 0)
		{
			$job = QueueTable::query()
				->setSelect(['*'])
				->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
				->where('ENTITY_ID', $activityId)
				->where('TYPE_ID', TranscribeCallRecording::TYPE_ID)
				->setLimit(1)
				->fetchObject()
			;
		}
		else
		{
			$job = null;
		}

		$result = $job ? TranscribeCallRecording::constructResult($job) : null;

		$this->transcribeCache[$activityId] = is_object($result) ? clone $result : null;

		return $result;
	}

	/**
	 * @return Result<SummarizeCallTranscriptionPayload>|null
	 */
	public function getSummarizeCallTranscriptionResultByActivity(
		int $activityId,
		?int $jobId = null,
		?int $ownerTypeId = null,
		?int $ownerId = null,
	): ?Result
	{
		$hasOwnerScope = (int)$ownerTypeId > 0 && (int)$ownerId > 0;
		$cacheKey = $hasOwnerScope
			? sprintf('%d-%d-%d-%d', $activityId, $jobId ?? 0, $ownerTypeId, $ownerId)
			: sprintf('%d-%d', $activityId, $jobId ?? 0)
		;
		if (array_key_exists($cacheKey, $this->summarizeCache))
		{
			return is_object($this->summarizeCache[$cacheKey])
				? clone $this->summarizeCache[$cacheKey]
				: null
			;
		}

		if ($activityId > 0)
		{
			$query = QueueTable::query()
				->setSelect(['*'])
				->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
				->where('ENTITY_ID', $activityId)
				->where('TYPE_ID', SummarizeCallTranscription::TYPE_ID)
				->addOrder('ID', 'DESC')
			;

			if (isset($jobId))
			{
				$query->where('ID', $jobId);
			}

			$job = $hasOwnerScope
				? $this->findLatestJobForOwner($query, (int)$ownerTypeId, (int)$ownerId)
				: $query->setLimit(1)->fetchObject()
			;
		}
		else
		{
			$job = null;
		}

		$result = $job ? SummarizeCallTranscription::constructResult($job) : null;

		$this->summarizeCache[$cacheKey] = is_object($result) ? clone $result : null;

		return $result;
	}

	/**
	 * @param ItemIdentifier $targetItem
	 * @param int|null $activityId
	 *
	 * @return Result<FillItemFieldsFromCallTranscriptionPayload>|null
	 */
	public function getFillItemFieldsFromCallTranscriptionResult(
		ItemIdentifier $targetItem,
		?int $activityId = null,
		?int $parentJobId = null,
	): ?Result
	{
		$cacheKey = $targetItem->getHash() . $activityId . ':' . ($parentJobId ?? 0);

		if (array_key_exists($cacheKey, $this->fillCache))
		{
			return is_object($this->fillCache[$cacheKey]) ? clone $this->fillCache[$cacheKey] : null;
		}

		$query = QueueTable::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE_ID', $targetItem->getEntityTypeId())
			->where('ENTITY_ID', $targetItem->getEntityId())
			->where('TYPE_ID', FillItemFieldsFromCallTranscription::TYPE_ID)
			// select last job
			->addOrder('ID', 'DESC')
			->setLimit(1)
		;

		if ($parentJobId > 0)
		{
			$query->where('PARENT_ID', $parentJobId);
		}
		elseif ($activityId > 0)
		{
			$parentSubQuery = QueueTable::query()
				->setSelect(['ID'])
				->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
				->where('ENTITY_ID', $activityId)
				->where('TYPE_ID', SummarizeCallTranscription::TYPE_ID)
			;

			$query->whereIn('PARENT_ID', $parentSubQuery);
		}

		$job = $query->fetchObject();

		$result = $job ? FillItemFieldsFromCallTranscription::constructResult($job) : null;

		if (is_object($result))
		{
			$clone = clone $result;

			$this->fillCache[$cacheKey] = $clone;
			$this->fillByIdCache[$result->getJobId()] = $clone;
		}
		else
		{
			$this->fillCache[$cacheKey] = null;
		}

		return $result;
	}

	/**
	 * @return Result<FillItemFieldsFromCallTranscriptionPayload>|null
	 */
	public function getFillItemFieldsFromCallTranscriptionResultById(int $jobId): ?Result
	{
		if (array_key_exists($jobId, $this->fillByIdCache))
		{
			return is_object($this->fillByIdCache[$jobId]) ? clone $this->fillByIdCache[$jobId] : null;
		}

		if ($jobId > 0)
		{
			$job = QueueTable::query()
				->setSelect(['*'])
				->where('ID', $jobId)
				->where('TYPE_ID', FillItemFieldsFromCallTranscription::TYPE_ID)
				->fetchObject()
			;
		}
		else
		{
			$job = null;
		}

		$result = $job ? FillItemFieldsFromCallTranscription::constructResult($job) : null;

		$this->fillByIdCache[$jobId] = is_object($result) ? clone $result : null;

		return $result;
	}

	/**
	 * @return Result<ScoreCallV2Payload>|null
	 */
	public function getCallScoringResult(int $activityId, ?int $jobId = null): ?Result
	{
		$cacheKey = sprintf('%d-%d', $activityId, $jobId ?? 0);
		if (array_key_exists($cacheKey, $this->callScoringCache))
		{
			return is_object($this->callScoringCache[$cacheKey])
				? clone $this->callScoringCache[$cacheKey]
				: null
			;
		}

		if ($activityId > 0)
		{
			$query = QueueTable::query()
				->setSelect(['*'])
				->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
				->where('ENTITY_ID', $activityId)
				->whereIn('TYPE_ID', [ScoreCall::TYPE_ID, ScoreCallV2::TYPE_ID])
				// select last job
				->addOrder('ID', 'DESC')
			;

			if (isset($jobId))
			{
				$query->where('ID', $jobId);
			}

			$job = $query
				->setLimit(1)
				->fetchObject()
			;
		}
		else
		{
			$job = null;
		}

		$result = null;
		if ($job)
		{
			$result = ((int)$job->requireTypeId() === ScoreCallV2::TYPE_ID)
				? ScoreCallV2::constructResult($job)
				: ScoreCall::constructResult($job)
			;
		}

		$this->callScoringCache[$cacheKey] = is_object($result) ? clone $result : null;

		return $result;
	}

	/**
	 * @return array<int, Result|null>
	 */
	public function getAnyCallScoringResultsByJobIds(int $activityId, array $jobIds): array
	{
		$jobIds = array_values(array_unique(array_filter(
			array_map('intval', $jobIds),
			static fn(int $jobId): bool => $jobId > 0,
		)));

		if ($activityId <= 0 || empty($jobIds))
		{
			return [];
		}

		$results = [];

		$jobs = QueueTable::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
			->where('ENTITY_ID', $activityId)
			->whereIn('ID', $jobIds)
			->whereIn('TYPE_ID', [ScoreCall::TYPE_ID, ScoreCallV2::TYPE_ID])
			->fetchCollection()
		;

		$loadedJobIds = [];
		foreach ($jobs as $job)
		{
			$jobId = (int)$job->getId();
			$result = $this->makeCallScoringResult($job);

			$results[$jobId] = $result;
			$loadedJobIds[$jobId] = true;
		}

		foreach ($jobIds as $jobId)
		{
			if (isset($loadedJobIds[$jobId]))
			{
				continue;
			}

			$results[$jobId] = null;
		}

		return $results;
	}

	private function makeCallScoringResult(?EO_Queue $job): ?Result
	{
		if (!$job)
		{
			return null;
		}

		return match ((int)$job->requireTypeId()) {
			ScoreCall::TYPE_ID => ScoreCall::constructResult($job),
			ScoreCallV2::TYPE_ID => ScoreCallV2::constructResult($job),
			default => null,
		};
	}

	/**
	 * @return Result<AnalyzeCommunicationPayload>|null
	 */
	public function getAnalyzeCommunicationResult(int $activityId, ?int $ownerTypeId = null, ?int $ownerId = null): ?Result
	{
		$hasOwnerScope = (int)$ownerTypeId > 0 && (int)$ownerId > 0;
		$cacheKey = $hasOwnerScope
			? sprintf('%d-%d-%d', $activityId, $ownerTypeId, $ownerId)
			: $activityId
		;
		if (array_key_exists($cacheKey, $this->analyzeCommunicationCache))
		{
			return is_object($this->analyzeCommunicationCache[$cacheKey])
				? clone $this->analyzeCommunicationCache[$cacheKey]
				: null
			;
		}

		if ($activityId > 0)
		{
			$query = QueueTable::query()
				->setSelect(['*'])
				->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
				->where('ENTITY_ID', $activityId)
				->where('TYPE_ID', AnalyzeCommunication::TYPE_ID)
				->addOrder('ID', 'DESC')
			;

			$job = $hasOwnerScope
				? $this->findLatestJobForOwner($query, (int)$ownerTypeId, (int)$ownerId)
				: $query->setLimit(1)->fetchObject()
			;
		}
		else
		{
			$job = null;
		}

		$result = $job ? AnalyzeCommunication::constructResult($job) : null;

		$this->analyzeCommunicationCache[$cacheKey] = is_object($result) ? clone $result : null;

		return $result;
	}

	private function findLatestJobForOwner(\Bitrix\Main\ORM\Query\Query $query, int $ownerTypeId, int $ownerId): ?EO_Queue
	{
		foreach ($this->iterateJobsForOwner($query, $ownerTypeId, $ownerId) as $job)
		{
			return $job;
		}

		return null;
	}

	/**
	 * @return \Generator<int, EO_Queue>
	 */
	private function iterateJobsForOwner(
		\Bitrix\Main\ORM\Query\Query $query,
		int $ownerTypeId,
		int $ownerId,
		bool $allowUnknownOwner = true,
	): \Generator
	{
		$offset = 0;
		while ($offset < self::OWNER_SCOPED_JOB_SCAN_LIMIT)
		{
			$jobs = $query
				->setLimit(self::OWNER_SCOPED_JOB_LOOKUP_LIMIT)
				->setOffset($offset)
				->fetchCollection()
			;
			if ($jobs->count() === 0)
			{
				return;
			}

			$this->warmJobOwnerCache($jobs);

			foreach ($jobs as $job)
			{
				if ($this->jobBelongsToOwner($job, $ownerTypeId, $ownerId, $allowUnknownOwner))
				{
					yield $job;
				}
			}

			if ($jobs->count() < self::OWNER_SCOPED_JOB_LOOKUP_LIMIT)
			{
				return;
			}

			$offset += self::OWNER_SCOPED_JOB_LOOKUP_LIMIT;
		}
	}

	private function jobBelongsToOwner(
		EO_Queue $job,
		int $ownerTypeId,
		int $ownerId,
		bool $allowUnknownOwner = true,
	): bool
	{
		$storedOwner = $this->resolveJobOwner($job);
		if ($storedOwner === null)
		{
			return $allowUnknownOwner;
		}

		return $storedOwner['ownerTypeId'] === $ownerTypeId && $storedOwner['ownerId'] === $ownerId;
	}

	private function resolveJobOwner(EO_Queue $job): ?array
	{
		$jobId = (int)$job->getId();
		if (!array_key_exists($jobId, $this->jobOwnerCache))
		{
			$this->warmJobOwnerCache([$job]);
		}

		return $this->jobOwnerCache[$jobId] ?? null;
	}

	/**
	 * @param iterable<EO_Queue> $jobs
	 */
	private function warmJobOwnerCache(iterable $jobs): void
	{
		$hashToJobIds = [];
		foreach ($jobs as $job)
		{
			$jobId = (int)$job->getId();
			if ($jobId <= 0 || array_key_exists($jobId, $this->jobOwnerCache))
			{
				continue;
			}

			$ownerFromResult = $this->extractOwnerFromJson($job->getResult());
			if ($ownerFromResult !== null)
			{
				$this->jobOwnerCache[$jobId] = $ownerFromResult;

				continue;
			}

			$hash = (string)$job->getHash();
			if ($hash === '')
			{
				$this->jobOwnerCache[$jobId] = null;

				continue;
			}

			$hashToJobIds[$hash][] = $jobId;
		}

		if (empty($hashToJobIds))
		{
			return;
		}

		$ownerByHash = array_fill_keys(array_keys($hashToJobIds), null);
		if (AIManager::isAvailable())
		{
			$rows = AIQueueTable::query()
				->setSelect(['HASH', 'CONTEXT'])
				->whereIn('HASH', array_keys($hashToJobIds))
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$hash = (string)($row['HASH'] ?? '');
				if ($hash === '' || !isset($hashToJobIds[$hash]))
				{
					continue;
				}

				$ownerByHash[$hash] ??= $this->extractOwnerFromContextJson($row['CONTEXT'] ?? null);
			}
		}

		foreach ($hashToJobIds as $hash => $jobIds)
		{
			foreach ($jobIds as $jobId)
			{
				$this->jobOwnerCache[$jobId] = $ownerByHash[$hash];
			}
		}
	}

	private function extractOwnerFromContextJson(?string $contextJson): ?array
	{
		if (!is_string($contextJson) || $contextJson === '')
		{
			return null;
		}

		try
		{
			$context = Context::unpack($contextJson);
		}
		catch (\Throwable)
		{
			return null;
		}

		$additionalInfo = $context->getParameters()['additionalInfo'] ?? [];

		return $this->extractOwnerFromArray(is_array($additionalInfo) ? $additionalInfo : []);
	}

	private function extendOwnerScopedSelect(array $fields): array
	{
		if (in_array('*', $fields, true))
		{
			return $fields;
		}

		return array_values(array_unique(array_merge($fields, ['ID', 'HASH', 'RESULT'])));
	}

	private function extractOwnerFromJson(?string $json): ?array
	{
		if (!is_string($json) || $json === '')
		{
			return null;
		}

		try
		{
			$data = Json::decode($json);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($data) ? $this->extractOwnerFromArray($data) : null;
	}

	private function extractOwnerFromArray(array $data): ?array
	{
		if (!isset($data['targetOwnerTypeId'], $data['targetOwnerId']))
		{
			return null;
		}

		$ownerTypeId = (int)$data['targetOwnerTypeId'];
		$ownerId = (int)$data['targetOwnerId'];
		if ($ownerTypeId <= 0 || $ownerId <= 0)
		{
			return null;
		}

		return [
			'ownerTypeId' => $ownerTypeId,
			'ownerId' => $ownerId,
		];
	}

	/**
	 * @return Result<ExtractScoringCriteriaPayload>|null
	 */
	public function getExtractScoringCriteriaResultById(int $id): ?Result
	{
		if (array_key_exists($id, $this->extractScoringCriteriaCache))
		{
			return is_object($this->extractScoringCriteriaCache[$id]) ? clone $this->extractScoringCriteriaCache[$id] : null;
		}

		if ($id > 0)
		{
			$job = QueueTable::query()
				->setSelect(['*'])
				->where('ENTITY_TYPE_ID', CCrmOwnerType::CopilotCallAssessment)
				->where('ENTITY_ID', $id)
				->where('TYPE_ID', ExtractScoringCriteria::TYPE_ID)
				->setLimit(1)
				->fetchObject()
			;
		}
		else
		{
			$job = null;
		}

		$result = $job ? ExtractScoringCriteria::constructResult($job) : null;

		$this->extractScoringCriteriaCache[$id] = is_object($result) ? clone $result : null;

		return $result;
	}

	/**
	 * @return Result<FillRepeatSaleTipsPayload>|null
	 */
	public function getFillRepeatSaleTipsByActivity(int $activityId): ?Result
	{
		if (array_key_exists($activityId, $this->fillRepeatSaleTips))
		{
			return is_object($this->fillRepeatSaleTips[$activityId])
				? clone $this->fillRepeatSaleTips[$activityId]
				: null
			;
		}

		if ($activityId > 0)
		{
			$job = QueueTable::query()
				->setSelect(['*'])
				->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
				->where('ENTITY_ID', $activityId)
				->where('TYPE_ID', FillRepeatSaleTips::TYPE_ID)
				->setLimit(1)
				->fetchObject()
			;
		}
		else
		{
			$job = null;
		}

		$result = $job ? FillRepeatSaleTips::constructResult($job) : null;

		$this->fillRepeatSaleTips[$activityId] = is_object($result) ? clone $result : null;

		return $result;
	}
	// endregion

	/**
	 * @param Result<FillItemFieldsFromCallTranscriptionPayload> $result
	 *
	 * @return Result
	 */
	public function updateFillItemFieldsFromCallTranscriptionResult(Result $result): Result
	{
		$updateResult = new Result(FillItemFieldsFromCallTranscription::TYPE_ID);

		if (
			$result->getJobId() <= 0
			|| !($result->getPayload() instanceof FillItemFieldsFromCallTranscriptionPayload)
			|| $result->getOperationStatus() === null
		)
		{
			return $updateResult->addError(
				new Error('Job id, payload and operation status are required for update', ErrorCode::REQUIRED_ARG_MISSING)
			);
		}

		$job = QueueTable::query()
			->setSelect(['RESULT', 'EXECUTION_STATUS', 'OPERATION_STATUS'])
			->where('ID', $result->getJobId())
			->fetchObject()
		;

		if (!$job)
		{
			return $updateResult->addError(ErrorCode::getNotFoundError());
		}

		if ($job->requireExecutionStatus() !== QueueTable::EXECUTION_STATUS_SUCCESS)
		{
			return $updateResult->addError(new Error(
				'Only successfully executed jobs can be updated', ErrorCode::JOB_IN_WRONG_STATUS,
			));
		}

		if (Result::isFinalOperationStatus($job->requireOperationStatus()))
		{
			return $updateResult->addError(ErrorCode::getOperationIsCompleteError());
		}

		$job->setOperationStatus($result->getOperationStatus());
		$job->setResult(Json::encode($result->getPayload(), 0));

		$saveResult = $job->save();
		if (!$saveResult->isSuccess())
		{
			$updateResult->addErrors($saveResult->getErrors());
		}

		return $updateResult;
	}

	public function getPendingJobByActivity(int $activityId, int $typeId): ?EO_Queue
	{
		if ($activityId <= 0)
		{
			return null;
		}

		return QueueTable::query()
			->setSelect(['ID', 'HASH'])
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
			->where('ENTITY_ID', $activityId)
			->where('TYPE_ID', $typeId)
			->where('EXECUTION_STATUS', QueueTable::EXECUTION_STATUS_PENDING)
			->setLimit(1)
			->fetchObject()
		;
	}

	public function getContextAdditionalInfoByJobId(?int $jobId): array
	{
		if (!$jobId)
		{
			return [];
		}

		$job = QueueTable::query()
			->setSelect(['HASH'])
			->where('ID', $jobId)
			->fetchObject()
		;
		$hash = $job?->getHash();
		if (!$hash)
		{
			return [];
		}

		$row = AIQueueTable::query()
			->setSelect(['CONTEXT'])
			->where('HASH', $hash)
			->setLimit(1)
			->fetch()
		;
		$contextJson = $row['CONTEXT'] ?? null;
		if (!is_string($contextJson) || $contextJson === '')
		{
			return [];
		}

		try
		{
			$context = Context::unpack($contextJson);
		}
		catch (\Throwable)
		{
			return [];
		}

		$additionalInfo = $context->getParameters()['additionalInfo'] ?? [];

		return is_array($additionalInfo) ? $additionalInfo : [];
	}

	public function getCallAssessmentJobByActivity(int $activityId, int $assessmentSettingsId): ?EO_Queue
	{
		if ($activityId <= 0 || $assessmentSettingsId <= 0)
		{
			return null;
		}

		$jobs = QueueTable::query()
			->setSelect(['ID', 'HASH', 'EXECUTION_STATUS', 'RETRY_COUNT'])
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
			->where('ENTITY_ID', $activityId)
			->where('TYPE_ID', ScoreCallV2::TYPE_ID)
			->addOrder('ID', 'DESC')
			->fetchCollection()
		;
		$hashes = [];
		foreach ($jobs as $job)
		{
			$hash = (string)$job->getHash();
			if ($hash !== '')
			{
				$hashes[] = $hash;
			}
		}

		if (empty($hashes))
		{
			return null;
		}

		$assessmentSettingsIdByHash = [];
		$rows = AIQueueTable::query()
			->setSelect(['HASH', 'CONTEXT'])
			->whereIn('HASH', array_values(array_unique($hashes)))
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$hash = (string)($row['HASH'] ?? '');
			if ($hash !== '')
			{
				$assessmentSettingsIdByHash[$hash] = $this->extractCallAssessmentSettingsIdFromContext(
					$row['CONTEXT'] ?? null,
				);
			}
		}

		foreach ($jobs as $job)
		{
			if (($assessmentSettingsIdByHash[(string)$job->getHash()] ?? null) === $assessmentSettingsId)
			{
				return $job;
			}
		}

		return null;
	}

	public function getCallAssessmentSettingsIdByJob(EO_Queue $job): ?int
	{
		$hash = (string)$job->getHash();
		if ($hash === '')
		{
			return null;
		}

		$row = AIQueueTable::query()
			->setSelect(['CONTEXT'])
			->where('HASH', $hash)
			->setLimit(1)
			->fetch()
		;

		return $this->extractCallAssessmentSettingsIdFromContext($row['CONTEXT'] ?? null);
	}

	private function extractCallAssessmentSettingsIdFromContext(?string $contextJson): ?int
	{
		if (!is_string($contextJson) || $contextJson === '')
		{
			return null;
		}

		try
		{
			$context = Context::unpack($contextJson);
		}
		catch (\Throwable)
		{
			return null;
		}

		$assessmentSettingsId = $context->getParameters()['additionalInfo']['assessment_settings_id'] ?? null;

		return is_numeric($assessmentSettingsId) && (int)$assessmentSettingsId > 0
			? (int)$assessmentSettingsId
			: null
		;
	}

	public function isJobOfSameTypeAlreadyExistsForTarget(ItemIdentifier $target, int $jobTypeId): bool
	{
		if (!in_array($jobTypeId, AIManager::getAllOperationTypes(), true))
		{
			return false;
		}

		$anotherJobOfSameTypeForThisTarget = QueueTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE_ID', $target->getEntityTypeId())
			->where('ENTITY_ID', $target->getEntityId())
			->where('TYPE_ID', $jobTypeId)
			->fetch()
		;

		return is_array($anotherJobOfSameTypeForThisTarget);
	}

	public function getFieldsFillingOperationById(int $id): ?EO_Queue
	{
		$query = QueueTable::query()
			->setSelect(['OPERATION_STATUS', 'ENTITY_TYPE_ID', 'ENTITY_ID'])
			->where('ID', $id)
			->where('TYPE_ID', FillItemFieldsFromCallTranscription::TYPE_ID);

		return $query->fetchObject();
	}

	public function getTotalFillItemFromCallRecordingScenarioDuration(int $fillFieldsJobId): ?int
	{
		$fillFieldsJob = QueueTable::query()
			->setSelect(['PARENT_ID', 'FINISHED_TIME'])
			->where('ID', $fillFieldsJobId)
			->where('TYPE_ID', FillItemFieldsFromCallTranscription::TYPE_ID)
			->fetchObject()
		;
		if (!$fillFieldsJob)
		{
			return null;
		}

		$summarizeJobSubQuery =  QueueTable::query()
			->setSelect(['PARENT_ID'])
			->where('ID', $fillFieldsJob->requireParentId())
			->where('TYPE_ID', SummarizeCallTranscription::TYPE_ID)
		;

		$transcribeJob = QueueTable::query()
			->setSelect(['CREATED_TIME'])
			->whereIn('ID', $summarizeJobSubQuery)
			->where('TYPE_ID', TranscribeCallRecording::TYPE_ID)
			->fetchObject()
		;
		if (!$transcribeJob)
		{
			return null;
		}

		return $fillFieldsJob->requireFinishedTime()->getTimestamp() - $transcribeJob->requireCreatedTime()->getTimestamp();
	}

	public function getSummarizeTranscriptionData(
		int $activityId,
		array $fields = ['*'],
		int $limit = 10,
		?int $ownerTypeId = null,
		?int $ownerId = null,
	): array
	{
		return $this->loadSummarizeTranscriptionData(
			$activityId,
			$fields,
			$limit,
			$ownerTypeId,
			$ownerId,
			true,
		);
	}

	private function loadSummarizeTranscriptionData(
		int $activityId,
		array $fields,
		int $limit,
		?int $ownerTypeId,
		?int $ownerId,
		bool $allowUnknownOwner,
	): array
	{
		if ($activityId <= 0)
		{
			return [];
		}

		$hasOwnerScope = (int)$ownerTypeId > 0 && (int)$ownerId > 0;
		$cacheKey = sprintf(
			'%d-%d-%s-%d-%d-%d',
			$activityId,
			$limit,
			implode(',', $fields),
			(int)$ownerTypeId,
			(int)$ownerId,
			(int)$allowUnknownOwner,
		);
		if (array_key_exists($cacheKey, $this->summarizeTranscriptionDataCache))
		{
			return $this->summarizeTranscriptionDataCache[$cacheKey];
		}

		$query = QueueTable::query()
			->setSelect($hasOwnerScope ? $this->extendOwnerScopedSelect($fields) : $fields)
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
			->where('ENTITY_ID', $activityId)
			->where('TYPE_ID', SummarizeCallTranscription::TYPE_ID)
			->where('EXECUTION_STATUS', QueueTable::EXECUTION_STATUS_SUCCESS)
		;

		if ($hasOwnerScope)
		{
			$query->setOrder(['FINISHED_TIME' => 'DESC', 'ID' => 'DESC']);

			$data = [];
			foreach ($this->iterateJobsForOwner(
				$query,
				(int)$ownerTypeId,
				(int)$ownerId,
				$allowUnknownOwner,
			) as $job)
			{
				$data[] = $job;
				if (count($data) >= $limit)
				{
					break;
				}
			}
		}
		else
		{
			$data = $query
				->setOrder(['FINISHED_TIME' => 'DESC', 'ID' => 'DESC'])
				->setLimit($limit)
				->fetchCollection()
				->getAll()
			;
		}

		$this->summarizeTranscriptionDataCache[$cacheKey] = $data;

		return $data;
	}

	/**
	 * @return array<int, array{
	 *     jobId: int,
	 *     summary: string,
	 *     theme: string,
	 *     createdAt: ?int,
	 *     languageId: ?string,
	 * }>
	 */
	public function getSummarizeCallTranscriptionHistoryByActivity(
		int $activityId,
		int $limit = 10,
		?int $ownerTypeId = null,
		?int $ownerId = null,
	): array
	{
		$result = [];
		$jobs = $this->loadSummarizeTranscriptionData(
			$activityId,
			['*'],
			$limit,
			$ownerTypeId,
			$ownerId,
			false,
		);
		$hasOwnerScope = (int)$ownerTypeId > 0 && (int)$ownerId > 0;

		foreach ($jobs as $job)
		{
			$jobId = (int)$job->getId();
			if ($jobId <= 0)
			{
				continue;
			}

			$summaryResult = SummarizeCallTranscription::constructResult($job);
			$cacheKey = $hasOwnerScope
				? sprintf('%d-%d-%d-%d', $activityId, $jobId, (int)$ownerTypeId, (int)$ownerId)
				: sprintf('%d-%d', $activityId, $jobId)
			;
			$this->summarizeCache[$cacheKey] = clone $summaryResult;

			if (!$summaryResult->isSuccess())
			{
				continue;
			}

			$payload = $summaryResult->getPayload();
			if (!$payload instanceof SummarizeCallTranscriptionPayload)
			{
				continue;
			}

			$result[] = [
				'jobId' => $jobId,
				'summary' => (string)$payload->summary,
				'theme' => (string)($payload->data?->theme ?? ''),
				'createdAt' => $job->getFinishedTime()?->getTimestamp(),
				'languageId' => $summaryResult->getLanguageId(),
			];

			if (count($result) >= $limit)
			{
				break;
			}
		}

		return $result;
	}

	public function isUserHasJobs(int $userId): bool
	{
		$useJob = QueueTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetchObject()
		;

		return (bool)$useJob;
	}

	/**
	 * Preloads all activity-targeted jobs for the given activityId in a single query,
	 * populating per-type caches so that subsequent resolve() calls hit warm caches.
	 *
	 * Only warms caches for operation types that use Activity as the target entity
	 * (Transcribe, Summarize, ScoreCall, AnalyzeCommunication, FillRepeatSaleTips).
	 * FillItemFields (targets Deal/Lead) and ExtractScoringCriteria are not covered.
	 */
	public function warmCacheForActivity(int $activityId): void
	{
		if ($activityId <= 0)
		{
			return;
		}

		$jobs = QueueTable::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
			->where('ENTITY_ID', $activityId)
			->addOrder('ID', 'DESC')
			->fetchCollection()
		;

		// Group by TYPE_ID, keep only the latest (first due to DESC order)
		$latestByType = [];
		foreach ($jobs as $job)
		{
			$typeId = (int)$job->requireTypeId();
			if (!isset($latestByType[$typeId]))
			{
				$latestByType[$typeId] = $job;
			}
		}

		// Populate per-type caches (only if not already cached)
		if (
			isset($latestByType[TranscribeCallRecording::TYPE_ID])
			&& !array_key_exists($activityId, $this->transcribeCache)
		)
		{
			$result = TranscribeCallRecording::constructResult($latestByType[TranscribeCallRecording::TYPE_ID]);
			$this->transcribeCache[$activityId] = is_object($result) ? clone $result : null;
		}

		$summarizeKey = sprintf('%d-0', $activityId);
		if (
			isset($latestByType[SummarizeCallTranscription::TYPE_ID])
			&& !array_key_exists($summarizeKey, $this->summarizeCache)
		)
		{
			$result = SummarizeCallTranscription::constructResult($latestByType[SummarizeCallTranscription::TYPE_ID]);
			$this->summarizeCache[$summarizeKey] = is_object($result) ? clone $result : null;
		}

		$scoringKey = sprintf('%d-0', $activityId);
		if (!array_key_exists($scoringKey, $this->callScoringCache))
		{
			$v1Job = $latestByType[ScoreCall::TYPE_ID] ?? null;
			$v2Job = $latestByType[ScoreCallV2::TYPE_ID] ?? null;
			$scoringJob = match (true) {
				$v1Job === null => $v2Job,
				$v2Job === null => $v1Job,
				default => ((int)$v2Job->requireId() >= (int)$v1Job->requireId()) ? $v2Job : $v1Job,
			};

			if ($scoringJob !== null)
			{
				$result = ((int)$scoringJob->requireTypeId() === ScoreCallV2::TYPE_ID)
					? ScoreCallV2::constructResult($scoringJob)
					: ScoreCall::constructResult($scoringJob)
				;
				$this->callScoringCache[$scoringKey] = is_object($result) ? clone $result : null;
			}
		}

		if (
			isset($latestByType[AnalyzeCommunication::TYPE_ID])
			&& !array_key_exists($activityId, $this->analyzeCommunicationCache)
		)
		{
			$result = AnalyzeCommunication::constructResult($latestByType[AnalyzeCommunication::TYPE_ID]);
			$this->analyzeCommunicationCache[$activityId] = is_object($result) ? clone $result : null;
		}

		if (
			isset($latestByType[FillRepeatSaleTips::TYPE_ID])
			&& !array_key_exists($activityId, $this->fillRepeatSaleTips)
		)
		{
			$result = FillRepeatSaleTips::constructResult($latestByType[FillRepeatSaleTips::TYPE_ID]);
			$this->fillRepeatSaleTips[$activityId] = is_object($result) ? clone $result : null;
		}
	}

	/**
	 * Warms the transcribe-result cache for many activities in a single query.
	 *
	 * Mirrors getTranscribeCallRecordingResultByActivity() but resolves a batch at once: one
	 * QueueTable read by ENTITY_ID IN (...) + TYPE_ID = TranscribeCallRecording instead of a query
	 * per activity. Activities without a job are cached as null so a later per-activity lookup still
	 * hits the cache. Only activities that are not cached yet are queried. The cache is invalidated
	 * by QueueTable ORM events, same as the single-activity path.
	 *
	 * @param int[] $activityIds
	 */
	public function warmTranscribeCacheForActivities(array $activityIds): void
	{
		$activityIds = array_values(array_unique(array_filter(
			array_map('intval', $activityIds),
			static fn (int $id): bool => $id > 0,
		)));

		$missing = array_values(array_filter(
			$activityIds,
			fn (int $id): bool => !array_key_exists($id, $this->transcribeCache),
		));
		if (empty($missing))
		{
			return;
		}

		$jobs = QueueTable::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
			->whereIn('ENTITY_ID', $missing)
			->where('TYPE_ID', TranscribeCallRecording::TYPE_ID)
			->fetchCollection()
		;

		$jobByActivity = [];
		foreach ($jobs as $job)
		{
			$activityId = (int)$job->requireEntityId();
			// keep the first job per activity (mirrors the single-activity LIMIT 1 read)
			if (!isset($jobByActivity[$activityId]))
			{
				$jobByActivity[$activityId] = $job;
			}
		}

		foreach ($missing as $activityId)
		{
			$job = $jobByActivity[$activityId] ?? null;
			$result = $job ? TranscribeCallRecording::constructResult($job) : null;
			$this->transcribeCache[$activityId] = is_object($result) ? clone $result : null;
		}
	}

	/**
	 * @internal
	 */
	public function cleanRuntimeCache(): void
	{
		$this->transcribeCache = [];
		$this->summarizeCache = [];
		$this->fillCache = [];
		$this->fillByIdCache = [];
		$this->callScoringCache = [];
		$this->extractScoringCriteriaCache = [];
		$this->fillRepeatSaleTips = [];
		$this->analyzeCommunicationCache = [];
		$this->summarizeTranscriptionDataCache = [];
		$this->jobOwnerCache = [];
	}
}
