<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Quality;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\Config;
use Bitrix\Crm\Integration\AI\Dto\SummarizeCallTranscriptionPayload;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
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

final class SummarizeCallTranscription extends AbstractOperation
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
		private readonly string $transcription,
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

	public static function canProceedToNextStep(Result $result, StepContext $context): bool
	{
		if (!$result->isSuccess())
		{
			return false;
		}

		$payload = $result->getPayload();

		return $payload instanceof SummarizeCallTranscriptionPayload && !empty($payload->summary);
	}

	public static function shouldRelaunch(Result $existingResult, StepContext $context): bool
	{
		if (
			$context->getActivityId() <= 0
			|| !$context->isManualLaunch()
			|| $context->getActivityProvider() !== OpenLine::getId()
		)
		{
			return false;
		}

		// Extras branch is a testing seam: unit tests inject pre-computed `messagesForCopilot`
		// and `lastMessagesVolumeForCopilot` via StepContext to avoid activity/broker fixtures.
		// Production callers do not set these — the fallback below reads the same data from the activity.
		$messages = $context->getExtra('messagesForCopilot');
		$lastMessagesVolume = $context->getExtra('lastMessagesVolumeForCopilot');
		if (is_string($messages) && is_numeric($lastMessagesVolume))
		{
			return mb_strlen($messages, 'UTF-8') >= (int)$lastMessagesVolume + OpenLine::CHAT_MESSAGE_COPILOT_PROCESSING_LIMIT;
		}

		return OpenLine::isCopilotProcessingAvailable(
			$context->getActivityId(),
			is_string($messages) ? $messages : '',
		);
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
		if (($activity['PROVIDER_ID'] ?? null) !== OpenLine::getId())
		{
			return parent::checkPreviousJobs($target, $parentId);
		}

		return parent::checkPreviousJobsAllowingSuccessfulRelaunch($target, $parentId);
	}

	protected function getAIPayload(): Main\Result
	{
		return PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setMarkers([
				'original_message' => $this->transcription,
			])->getResult()
		;
	}

	protected function getContextLanguageId(): string
	{
		$itemIdentifier = $this->targetResolver->findTarget($this->target->getEntityId());
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
		$activityId = $result->getTarget()?->getEntityId();
		$nextTarget = (new TargetResolver())->findTarget($activityId);
		if ($nextTarget)
		{
			OpenLine::saveLastMessagesVolumeForCopilot($activityId);
			self::notifyTimelinesAboutActivityUpdate($activityId, true);
		}
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void {}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true
	): void
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
				self::sendCallParsingAnalyticsEvent(
					$result,
					$activityId
				);
			}
		}
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		if ($activityId > 0)
		{
			self::notifyTimelinesAboutActivityUpdate($activityId);
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
}
