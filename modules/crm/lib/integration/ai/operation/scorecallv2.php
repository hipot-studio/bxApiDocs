<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI;
use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentItem;
use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\CallScoringEvent;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\ItemFactory;
use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Copilot\PullManager;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Config;
use Bitrix\Crm\Integration\AI\Dto\Scoring\ScoreCallV2Payload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\CallScoringV2;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\MultiValueStoreService;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\AI\Controller;
use Bitrix\Main;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use CCrmActivity;
use CCrmOwnerType;

final class ScoreCallV2 extends AbstractOperation
{
	use JobCallAssessmentBindingTrait;

	public const TYPE_ID = 14;
	public const CONTEXT_ID = 'score_call';

	protected const PAYLOAD_CLASS = ScoreCallV2Payload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE;

	private string $transcription = '';
	private int $assessmentSettingsId = 0;
	private string $criteriaData = '';
	private ?Main\Result $aiPayloadResultCache = null;

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return parent::isAccessGranted($userId, $target)
			&& CCrmActivity::CheckItemUpdatePermission(
				['ID' => $target->getEntityId()],
				Container::getInstance()->getUserPermissions($userId)->getCrmPermissions(),
			);
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		if ($target->getEntityTypeId() === CCrmOwnerType::Activity)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
			$providerId = $activity['PROVIDER_ID'] ?? null;
			if ($providerId === Call::getId())
			{
				return true;
			}
		}

		return false;
	}

	public function setTranscription(string $transcription): self
	{
		$this->transcription = $transcription;

		return $this;
	}

	public function setAssessmentSettingsId(int $assessmentSettingsId): self
	{
		$this->assessmentSettingsId = $assessmentSettingsId;

		return $this;
	}

	public function launch(): Result
	{
		$this->prepareCriteriaData();

		return parent::launch();
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): Main\Result
	{
		return self::checkPreviousJob(self::findDuplicateJob($target, $parentId));
	}

	protected function checkPreviousJobsBeforeLaunch(): Main\Result
	{
		$previousJob = JobRepository::getInstance()->getCallAssessmentJobByActivity(
			$this->target->getEntityId(),
			$this->assessmentSettingsId,
		);

		return self::checkPreviousJob($previousJob);
	}

	private static function checkPreviousJob(?EO_Queue $previousJob): Main\Result
	{
		$result = new Main\Result();

		if (!$previousJob)
		{
			return $result; // new job
		}

		if ($previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_SUCCESS)
		{
			return $result; // success previous job
		}

		if ($previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_PENDING)
		{
			return $result->addError(ErrorCode::getJobAlreadyExistsError()); // previous job in progress
		}

		if (
			$previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR
			&& $previousJob->requireRetryCount() >= Result::MAX_RETRY_COUNT
		)
		{
			return $result->addError(ErrorCode::getJobMaxRetriesExceededError());
		}

		$result->setData(['previousJob' => $previousJob]); // update only error jobs

		return $result;
	}

	protected function getAIPayload(): Main\Result
	{
		if ($this->aiPayloadResultCache === null)
		{
			$this->aiPayloadResultCache = $this->buildAIPayloadResult();
		}

		return $this->aiPayloadResultCache;
	}

	private function buildAIPayloadResult(): Main\Result
	{
		$additionalData = [
			'transcription' => $this->transcription,
			'assessmentSettingsId' => $this->assessmentSettingsId,
		];

		$result = PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setAdditionalData($additionalData)
			->setMarkers([])
			->getResult()
		;

		/** @var IPayload $payload */
		$payload = $result->getData()['payload'];
		if (!$this->isPayloadMarkersValid($payload->getMarkers()))
		{
			$error = ErrorCode::getInvalidPayloadMarkersForScoreCallV2Error();

			return (new Main\Result())->addError($error);
		}

		return $result;
	}

	private function prepareCriteriaData(): void
	{
		$result = $this->getAIPayload();
		$payload = $result->getData()['payload'] ?? null;
		if (!$payload instanceof IPayload)
		{
			return;
		}

		$criteria = $payload->getMarkers()['criteria'] ?? '';
		if (!is_string($criteria) || $criteria === '')
		{
			return;
		}

		try
		{
			$decoded = Json::decode($criteria);
		}
		catch (Main\ArgumentException)
		{
			return;
		}

		if (!is_array($decoded))
		{
			return;
		}

		$criteriaData = [];
		foreach ($decoded as $criterion)
		{
			if (!is_array($criterion))
			{
				continue;
			}

			$name = trim((string)($criterion['name'] ?? ''));
			if ($name === '')
			{
				continue;
			}

			$criteriaData[] = [
				'name' => $name,
				'description' => (string)($criterion['description'] ?? ''),
			];
		}

		if (!empty($criteriaData))
		{
			$this->criteriaData = Json::encode($criteriaData);
		}
	}

	private function isPayloadMarkersValid(array $markers): bool
	{
		if (empty($markers))
		{
			return false;
		}

		$criteria = $markers['criteria'] ?? '';
		if (empty($criteria))
		{
			return false;
		}

		try
		{
			$decoded = Json::decode($criteria);
			if (empty($decoded))
			{
				return false;
			}
		}
		catch (Main\ArgumentException)
		{
			return false;
		}

		$scriptName = $markers['script_name'] ?? '';
		if (empty($scriptName))
		{
			return false;
		}

		return true;
	}

	protected function getContextLanguageId(): string
	{
		$itemIdentifier = (new TargetResolver())->findTarget($this->target->getEntityId());
		if ($itemIdentifier)
		{
			return Config::getLanguageId(
				$this->userId,
				$itemIdentifier->getEntityTypeId(),
				$itemIdentifier->getCategoryId(),
			);
		}

		return parent::getContextLanguageId();
	}

	protected function getContextAdditionalInfo(): array
	{
		$result = parent::getContextAdditionalInfo();

		$result['assessment_settings_id'] = $this->assessmentSettingsId ?: null;
		$result['criteria_data'] = $this->criteriaData ?: null;

		return $result;
	}

	// region notify
	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		$nextTarget = (new TargetResolver())->findTarget($activityId);
		if ($nextTarget)
		{
			self::notifyTimelinesAboutActivityUpdate($activityId, true);
		}
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
		if (self::isCriteriaListEmpty((array)$result->getPayload()?->criteriaScores))
		{
			return;
		}

		$activityId = $result->getTarget()?->getEntityId();
		$nextTarget = (new TargetResolver())->findTarget($activityId);
		if ($nextTarget)
		{
			self::notifyTimelinesAboutActivityUpdate($activityId, true);
		}
	}

	protected static function notifyAboutJobError(Result $result, bool $withSyncBadges = true, bool $withSendAnalytics = true, ?ItemIdentifier $target = null): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		$nextTarget = (new TargetResolver())->findTarget($activityId);
		if ($nextTarget)
		{
			if ($withSyncBadges)
			{
				Controller::getInstance()->onLaunchError(
					$nextTarget,
					$activityId,
					[
						'OPERATION_TYPE_ID' => self::TYPE_ID,
						'ENGINE_ID' => self::$engineId,
						'ERRORS' => array_unique($result->getErrorMessages()),
					],
					$result->getUserId(),
				);

				self::syncBadges($activityId, Badge\Type\AiCallFieldsFillingResult::ERROR_PROCESS_VALUE);
			}

			self::notifyTimelinesAboutActivityUpdate($activityId);

			if ($withSendAnalytics)
			{
				self::sendCallParsingAnalyticsEvent($result, $activityId);
			}
		}

		self::notifyCallQualityUpdate($activityId, 'error');
	}

	protected static function notifyCallQualityUpdate(int $activityId, string $status, array $params = []): void
	{
		$params['status'] = $status;

		(new PullManager())->sendAddScoringPullEvent($activityId, $params);
	}
	// endregion

	protected static function extractPayloadFromAIResult(AI\Result $result, EO_Queue $job): Dto
	{
		return self::extractScorePayloadFromAIResult($result);
	}

	public static function extractAssessmentFromAIResult(AI\Result $result): ?int
	{
		$payload = self::extractScorePayloadFromAIResult($result);
		if ($payload->hasValidationErrors())
		{
			return null;
		}

		return self::isCriteriaListEmpty($payload->criteriaScores)
			? null
			: self::getAssessmentsValue($payload);
	}

	private static function extractScorePayloadFromAIResult(AI\Result $result): ScoreCallV2Payload
	{
		$json = self::extractPayloadPrettifiedData($result);
		if (empty($json))
		{
			return new ScoreCallV2Payload([]);
		}

		return new ScoreCallV2Payload([
			'criteriaScores' => $json['criteria_scores'] ?? [],
			'recommendations' => self::extractPayloadString($json['recommendations'] ?? ''),
		]);
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new CallScoringV2();
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		if ($activityId === null)
		{
			AIManager::logger()->error(
				'{date}: {class}: job finished without a target — skipping persist',
				['class' => self::class],
			);

			return;
		}

		try
		{
			$nextTarget = (new TargetResolver())->findTarget($activityId);
			if ($nextTarget === null)
			{
				AIManager::logger()->info(
					'{date}: {class}: target gone while job was in flight (activityId={activityId}) — skipping persist',
					['class' => self::class, 'activityId' => $activityId],
				);

				self::notifyCallQualityUpdate($activityId, 'error');

				return;
			}

			/** @var ScoreCallV2Payload $payload */
			$payload = $result->getPayload();
			if (!$payload || !$result->isSuccess())
			{
				AIManager::logger()->error(
					'{date}: {class}: Error while trying to save scores because of job error: {target}' . PHP_EOL,
					[
						'class' => self::class,
						'target' => $result->getTarget(),
					],
				);

				self::notifyCallQualityUpdate($activityId, 'error');

				return;
			}

			if (self::isCriteriaListEmpty($payload->criteriaScores))
			{
				Controller::getInstance()->onCallScoringEmptyResult(
					$nextTarget,
					$activityId,
					[
						'RECOMMENDATIONS' => $payload->recommendations,
					],
					$result->getUserId(),
				);

				self::notifyTimelinesAboutActivityUpdate($activityId);
				self::notifyCallQualityUpdate($activityId, 'error');

				return;
			}

			$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
			$userId = $activity['RESPONSIBLE_ID'] ?? $result->getUserId();

			$assessmentSettingsId = isset($context)
				? $context->getParameters()['additionalInfo']['assessment_settings_id'] ?? null
				: null;
			$criteriaData = isset($context)
				? (string)($context->getParameters()['additionalInfo']['criteria_data'] ?? '')
				: '';
			$assessmentSettings = self::getAssessmentSettings($activityId, $assessmentSettingsId);
			$assessment = self::getAssessmentsValue($payload);
			$controller = AiQualityAssessmentController::getInstance();
			$prevRatedItemIdList = $controller->getList([
				'select' => ['ID'],
				'filter' => [
					'=ACTIVITY_ID' => $activityId,
					'=ACTIVITY_TYPE' => AiQualityAssessmentTable::ACTIVITY_TYPE_CALL,
					'=RATED_USER_ID' => $userId,
				],
			])->getAll();
			$prevRatedItemIdList = array_map(static fn(object $item) => $item->getId(), $prevRatedItemIdList);

			$saveResult = $controller
				->add(
					AiQualityAssessmentItem::createFromEntityFields([
						'ACTIVITY_ID' => $activityId,
						'ASSESSMENT_SETTING_ID' => $assessmentSettings?->getId(),
						'JOB_ID' => $result->getJobId(),
						'PROMPT' => $assessmentSettings?->getPrompt() ?? '',
						'ASSESSMENT' => $assessment,
						'ASSESSMENT_AVG' => $controller->getNewAvgAssessmentValue($userId, $assessment),
						'USE_IN_RATING' => true,
						'RATED_USER_ID' => $userId,
						'CRITERIA_DATA' => $criteriaData,
					]),
				)
			;

			if (!$saveResult->isSuccess())
			{
				AIManager::logger()->critical(
					'{date}: {class}: Error while trying to save scores because of error: {errors}',
					[
						'class' => self::class,
						'errors' => $saveResult->getErrors(),
					],
				);

				self::notifyCallQualityUpdate($activityId, 'error');

				return;
			}

			self::notifyCallQualityUpdate(
				$activityId,
				'success',
				[
					'jobId' => $result->getJobId(),
					'assessmentSettingsId' => $assessmentSettings?->getId(),
					'ratedUserId' => $userId,
				],
			);

			self::cleanBadgeByType($activityId, Badge\Badge::AI_FIELDS_FILLING_RESULT);
			self::trySyncScoreStatusBadge(
				$activityId,
				$assessment,
				$assessmentSettings?->getLowBorder(),
			);

			self::sendCallScoringAnalytics(
				(int)$result->getJobId(),
				$assessmentSettings?->getId() ?? 0,
				$assessment,
				$activityId,
			);

			if ($prevRatedItemIdList)
			{
				AiQualityAssessmentTable::updateMulti(
					$prevRatedItemIdList,
					[
						'USE_IN_RATING' => 'N',
					],
					true,
				);
			}
		}
		finally
		{
			self::cleanupJobCallAssessmentBinding($result->getParentJobId(), $activityId);
		}
	}

	private static function sendCallScoringAnalytics(
		int $jobId,
		int $callAssessmentId,
		int $callScore,
		int $activityId,
	): void
	{
		$event = (new CallScoringEvent())
			->setCallAssessmentId($callAssessmentId)
			->setCallScore((string)$callScore)
			->setActivityId($activityId)
		;

		if ($jobId > 0)
		{
			$row = QueueTable::getList([
				'select' => ['CREATED_TIME', 'FINISHED_TIME'],
				'filter' => ['=ID' => $jobId],
				'limit' => 1,
			])->fetch();
			$createdTime = $row['CREATED_TIME'] ?? null;
			$finishedTime = $row['FINISHED_TIME'] ?? null;
			if ($createdTime instanceof DateTime && $finishedTime instanceof DateTime)
			{
				$event->setStepDurationSeconds(
					$finishedTime->getTimestamp() - $createdTime->getTimestamp(),
				);
			}
		}

		$event->buildEvent()->send();
	}

	private static function getAssessmentSettings(int $activityId, ?int $assessmentSettingsId = null, ?int $parentJobId = null): ?CallAssessmentItem
	{
		static $cache = [];

		$cacheKey = $activityId . ':' . ($assessmentSettingsId ?? '') . ':' . ($parentJobId ?? '');
		if (array_key_exists($cacheKey, $cache))
		{
			return $cache[$cacheKey];
		}

		$result = null;

		if ($assessmentSettingsId === null && $parentJobId !== null)
		{
			$key = self::generateJobCallAssessmentBindKey($parentJobId, $activityId);
			$assessmentSettingsIdValue = MultiValueStoreService::getInstance()->getFirstValue($key);
			if (is_numeric($assessmentSettingsIdValue))
			{
				$assessmentSettingsId = (int)$assessmentSettingsIdValue;
			}
		}

		if (isset($assessmentSettingsId))
		{
			$assessmentSettingsItem = CopilotCallAssessmentController::getInstance()->getById($assessmentSettingsId);
			if ($assessmentSettingsItem)
			{
				$result = CallAssessmentItem::createFromEntity($assessmentSettingsItem);
			}
		}

		if (!$result)
		{
			$result = ItemFactory::getByActivityId($activityId);
		}

		$cache[$cacheKey] = $result;

		return $result;
	}

	private static function getAssessmentsValue(ScoreCallV2Payload $payload): int
	{
		$criteriaList = $payload->criteriaScores;
		if (empty($criteriaList))
		{
			return 0;
		}

		// filter out unrated criteria
		$criteriaList = array_values(
			array_filter(
				$criteriaList,
				static fn(object $item) => isset($item->met) && is_bool($item->met),
			),
		);

		$totalCount = count($criteriaList);
		if ($totalCount === 0)
		{
			return 0;
		}

		$countTrue = 0;
		foreach ($criteriaList as $item)
		{
			if ($item->met)
			{
				++$countTrue;
			}
		}

		return (int)round($countTrue / $totalCount * 100);
	}

	private static function isCriteriaListEmpty(array $list): bool
	{
		$criteriaList = array_filter(
			$list,
			static fn(object $item) => isset($item->met) && is_bool($item->met),
		);

		return empty($criteriaList);
	}

	private static function trySyncScoreStatusBadge(int $activityId, int $assessment, int $assessmentLowBorder): void
	{
		$itemIdentifier = (new TargetResolver())->findTarget($activityId);
		if (!$itemIdentifier)
		{
			return;
		}

		Badge\Badge::deleteByEntity($itemIdentifier, Badge\Badge::AI_CALL_SCORING_STATUS);

		if ($assessment > $assessmentLowBorder)
		{
			return;
		}

		$badge = Container::getInstance()->getBadge(Badge\Badge::AI_CALL_SCORING_STATUS, Badge\Type\AiCallScoringStatus::FAILED_VALUE);
		$sourceIdentifier = new Badge\SourceIdentifier(
			Badge\SourceIdentifier::CRM_OWNER_TYPE_PROVIDER,
			CCrmOwnerType::Activity,
			$activityId,
		);

		$badge->bind($itemIdentifier, $sourceIdentifier);
	}
}
