<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Engine;
use Bitrix\AI\Quality;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\Config;
use Bitrix\Crm\Integration\AI\Dto\SummarizeCallTranscriptionPayload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\SummaryEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\AI\Controller;
use Bitrix\Main;
use CCrmActivity;
use CCrmOwnerType;

class SummarizeCallTranscription extends AbstractOperation
{
	public const TYPE_ID = 2;
	public const CONTEXT_ID = 'summarize_call_transcription';

	public const SUPPORTED_TARGET_ENTITY_TYPE_IDS = [
		CCrmOwnerType::Activity,
	];
	public const SUPPORTED_ACTIVITY_PROVIDER_IDS = [
		Call::ACTIVITY_PROVIDER_ID,
		OpenLine::ACTIVITY_PROVIDER_ID,
	];

	protected const PAYLOAD_CLASS = SummarizeCallTranscriptionPayload::class;

	public function __construct(
		ItemIdentifier $target,
		private string $transcription,
		?int $userId = null,
		?int $parentJobId = null,
	)
	{
		parent::__construct($target, $userId, $parentJobId);
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return parent::isAccessGranted($userId, $target)
			&& CCrmActivity::CheckItemUpdatePermission(
				['ID' => $target->getEntityId()],
				Container::getInstance()->getUserPermissions($userId)->getCrmPermissions(),
			)
		;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		if ($target->getEntityTypeId() === CCrmOwnerType::Activity)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
			if (
				$activity
				&& isset($activity['PROVIDER_ID'])
				&& in_array($activity['PROVIDER_ID'], self::SUPPORTED_ACTIVITY_PROVIDER_IDS, true)
			)
			{
				return true;
			}
		}

		return false;
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): Main\Result
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
		if (!Scenario::isScenarioWithSkipTranscription($activity['PROVIDER_ID']))
		{
			return parent::checkPreviousJobs($target, $parentId);
		}

		$result = new Main\Result();

		$previousJob = self::findDuplicateJob($target, $parentId);
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
		return PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setMarkers([
				'original_message' => $this->transcription,
			])->getResult()
		;
	}

	final protected function getContextLanguageId(): string
	{
		$itemIdentifier = (new Orchestrator())->findPossibleFillFieldsTarget($this->target->getEntityId());
		if ($itemIdentifier)
		{
			return Config::getLanguageId(
				$this->userId,
				$itemIdentifier->getEntityTypeId(),
				$itemIdentifier->getCategoryId()
			);
		}

		return parent::getContextLanguageId();
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		$nextTarget = (new Orchestrator())->findPossibleFillFieldsTarget($result->getTarget()?->getEntityId());

		if ($nextTarget)
		{
			$activityId = $result->getTarget()?->getEntityId();

			Controller::getInstance()->onStartRecordTranscriptSummary(
				$nextTarget,
				$activityId,
				$result->getUserId(),
			);

			self::saveActivitySettings($activityId);
		}
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
		$nextTarget = (new Orchestrator())->findPossibleFillFieldsTarget($result->getTarget()?->getEntityId());
		if ($nextTarget)
		{
			Controller::getInstance()->onFinishRecordTranscriptSummary(
				$nextTarget,
				$result->getTarget()?->getEntityId(),
				[
					'JOB_ID' => $result->getJobId(),
				],
				$result->getUserId(),
			);
		}
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true
	): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		$nextTarget = (new Orchestrator())->findPossibleFillFieldsTarget($activityId);
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
				self::sendCallParsingAnalyticsEvent(
					$result,
					$activityId
				);
			}
		}
	}

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		return new SummarizeCallTranscriptionPayload([
			'summary' => $result->getPrettifiedData(),
		]);
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new SummaryEvent();
	}

	protected static function setQuality(Engine $engine): void
	{
		if (isset(Quality::QUALITIES['summarize']) && method_exists($engine->getIEngine(), 'setQuality'))
		{
			$engine->getIEngine()->setQuality(Quality::QUALITIES['summarize']);
		}
	}

	private static function saveActivitySettings(int $activityId): void
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		if ($activity['PROVIDER_ID'] !== OpenLine::getId())
		{
			return;
		}

		// save additional setting to avoid re-fetching messages in each job retry
		$messages = OpenLine::getMessagesForCopilot($activityId);

		CCrmActivity::Update($activityId, ['SETTINGS' => [
			...$activity['SETTINGS'],
			'LAST_MESSAGES_VOLUME' => (int)(mb_strlen($messages, 'UTF-8')),
		]]);
	}
}
