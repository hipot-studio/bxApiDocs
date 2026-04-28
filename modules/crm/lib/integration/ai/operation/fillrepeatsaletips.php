<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Crm\Activity\Analytics\Dictionary;
use Bitrix\Crm\Activity\Provider\RepeatSale;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\FillRepeatSaleTipsPayload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\Activity\EditActivityEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\FillRepeatSaleTipsEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\Logger;
use Bitrix\Crm\RepeatSale\Segment\SegmentItemChecker;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\AI\Controller;
use Bitrix\Main;
use CCrmActivity;
use CCrmOwnerType;

final class FillRepeatSaleTips extends AbstractFillRepeatSaleTips
{
	public const TYPE_ID = 6;

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		if (!parent::isAccessGranted($userId, $target))
		{
			return false;
		}

		return CCrmActivity::CheckItemUpdatePermission(
			['ID' => $target->getEntityId()],
			Container::getInstance()->getUserPermissions($userId)->getCrmPermissions(),
		);
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		if ($target->getEntityTypeId() === CCrmOwnerType::Activity)
		{
			$activityData = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
			$providerId = $activityData['PROVIDER_ID'] ?? null;
			if ($providerId === RepeatSale::getId())
			{
				return true;
			}
		}

		return false;
	}

	protected function getAIPayload(): Main\Result
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($this->target->getEntityId());
		$checkerResult = SegmentItemChecker::getInstance()
			->setItemByActivity($activity)
			->run()
		;
		if (!$checkerResult->isSuccess())
		{
			return (new Main\Result())->addError($checkerResult->getError());
		}

		$result = PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setEncodedMarkers(['segment_data', 'crm_data'])
			->setMarkers([])
			->getResult()
		;

		/** @var IPayload $payload */
		$payload = $result->getData()['payload'];
		if (!$this->isPayloadMarkersValid($payload->getMarkers()))
		{
			return (new Main\Result())->addError(
				ErrorCode::getInvalidPayloadMarkersForFillRepeatSaleTipsError()
			);
		}

		return $result;
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		// operation is not used in the timeline
		// temporary use method for to collect information about tasks sent to the AI queue
		$activityId = $result->getTarget()?->getEntityId() ?? 0;
		$jobId = $result->getJobId() ?? 0;

		$logMarkAiStart = $result->isManualLaunch()
			? Logger::LOG_MARK_AI_MANUAL_LAUNCH
			: Logger::LOG_MARK_AI_AUTO_LAUNCH
		;
		$logMarkAiPaid = self::isSponsoredOperation()
			? Logger::LOG_MARK_AI_NOT_PAID
			: Logger::LOG_MARK_AI_PAID
		;

		(new Logger('RepeatSaleVsAI'))->info(
			'{date}: Task with job ID {jobId} for activity ID {activityId} has been sent to AI queue: {markAiStart} {markAiPaid}' . PHP_EOL,
			[
				'jobId' => $jobId,
				'activityId' => $activityId,
				'markAiStart' => $logMarkAiStart,
				'markAiPaid' => $logMarkAiPaid,
			],
		);
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
		// operation is not used in the timeline
	}

	protected static function notifyAboutLimitExceededError(Result $result): void
	{
		// not implemented yet
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var FillRepeatSaleTipsPayload $payload */
		$payload = $result->getPayload();
		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error while trying to save activity of job error: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$analyticsStatus = \Bitrix\Crm\Integration\Analytics\Dictionary::STATUS_ERROR;

		$activityId = $result->getTarget()?->getEntityId();
		$saveResult = CCrmActivity::Update($activityId, [
			'DESCRIPTION' => RepeatSale::createDescriptionFromPayload(
				$payload,
				false,
				$context?->getLanguage()?->getCode()
			),
		]);
		if ($saveResult)
		{
			$analyticsStatus = \Bitrix\Crm\Integration\Analytics\Dictionary::STATUS_SUCCESS;

			self::cleanBadgeByType($activityId, Badge\Badge::AI_FIELDS_FILLING_RESULT);
			self::notifyTimelinesAboutActivityUpdate($activityId);
		}
		else
		{
			AIManager::logger()->error(
				'{date}: {class}: Error while trying to save activity of job error: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);
		}

		EditActivityEvent::createDefault(CCrmOwnerType::Deal) // @todo: extent entity type ID in future
			->setType(Dictionary::REPEAT_SALE_TYPE)
			->setElement(Dictionary::REPEAT_SALE_ELEMENT_SYS)
			->setStatus($analyticsStatus)
			->setP5('segment', str_replace('_', '-', RepeatSale::getSegmentCodeByActivity($activityId)))
			->buildEvent()
			->send()
		;
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
		}
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new FillRepeatSaleTipsEvent();
	}
}
