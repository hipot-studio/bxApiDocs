<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Crm\Activity\Analytics\Dictionary;
use Bitrix\Crm\Activity\Provider\RepeatSale;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
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
use Bitrix\Crm\RepeatSale\Segment\SegmentCode;
use Bitrix\Crm\RepeatSale\Segment\SegmentItemChecker;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\AI\Controller;
use Bitrix\Main;
use CCrmActivity;
use CCrmOwnerType;

final class FillRepeatSaleTips extends AbstractFillRepeatSaleTips
{
	public const TYPE_ID = 6;

	/** Read by the timeline card: with no job behind the activity, this is what "no tips" means there. */
	public const PROVIDER_PARAM_DATA_INSUFFICIENT = 'IS_AI_DATA_INSUFFICIENT';

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

		return $this->refuseWhenMarkersAreInsufficient($payload->getMarkers(), $activity) ?? $result;
	}

	/**
	 * The refusal ends launch() before the queue row is written, so the activity gets no job and no
	 * answer at all. Without a mark the card cannot tell that from an answer still on its way, hence
	 * the flag left on the activity right here.
	 */
	private function refuseWhenMarkersAreInsufficient(array $markers, ?array $activity): ?Main\Result
	{
		if ($this->isPayloadMarkersValid($markers))
		{
			return null;
		}

		$activityId = $this->target->getEntityId();
		$providerParams = self::buildDataInsufficientProviderParams($activity);
		if ($providerParams !== null)
		{
			// Repeat-sale automation runs in a system context, so the service mark bypasses permission checks.
			$isUpdated = (bool)CCrmActivity::Update(
				$activityId,
				['PROVIDER_PARAMS' => $providerParams],
				false,
				false,
			);
			if (!$isUpdated)
			{
				AIManager::logger()->error(
					'{date}: {class}: Error while trying to mark activity as short of AI data: {activityId}'
					. PHP_EOL,
					[
						'class' => self::class,
						'activityId' => $activityId,
					],
				);
			}
		}

		return (new Main\Result())->addError(
			ErrorCode::getInvalidPayloadMarkersForFillRepeatSaleTipsError(),
		);
	}

	/**
	 * @return array|null the whole PROVIDER_PARAMS to write, or null when there is nothing to change
	 */
	private static function buildDataInsufficientProviderParams(?array $activity): ?array
	{
		if (empty($activity))
		{
			return null;
		}

		$providerParams = $activity['PROVIDER_PARAMS'] ?? [];
		if (!is_array($providerParams))
		{
			$providerParams = [];
		}

		if (!empty($providerParams[self::PROVIDER_PARAM_DATA_INSUFFICIENT]))
		{
			return null;
		}

		$providerParams[self::PROVIDER_PARAM_DATA_INSUFFICIENT] = true;

		return $providerParams;
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		// operation is not used in the timeline
		// temporary use method for to collect information about tasks sent to the AI queue
		$activityId = $result->getTarget()?->getEntityId() ?? 0;
		$jobId = $result->getJobId() ?? 0;

		$logMarkAiStart = $result->isManualLaunch()
			? Logger::LOG_MARK_AI_MANUAL_LAUNCH
			: Logger::LOG_MARK_AI_AUTO_LAUNCH;
		$logMarkAiPaid = self::isSponsoredOperation()
			? Logger::LOG_MARK_AI_NOT_PAID
			: Logger::LOG_MARK_AI_PAID;

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

		$activityId = $result->getTarget()?->getEntityId() ?? 0;

		$analyticsStatus = self::applyJobResultToActivity($activityId, $payload, $context)
			? \Bitrix\Crm\Integration\Analytics\Dictionary::STATUS_SUCCESS
			: \Bitrix\Crm\Integration\Analytics\Dictionary::STATUS_ERROR;

		$segmentCode = SegmentCode::tryFrom(RepeatSale::getSegmentCodeByActivity($activityId));

		$event = EditActivityEvent::createDefault(CCrmOwnerType::Deal) // @todo: extent entity type ID in future
			->setType(Dictionary::REPEAT_SALE_TYPE)
			->setElement(Dictionary::REPEAT_SALE_ELEMENT_SYS)
			->setStatus($analyticsStatus)
		;
		if ($segmentCode !== null)
		{
			$event->setP5('segment', $segmentCode->toAnalyticsAlias());
		}
		$event
			->buildEvent()
			->send()
		;
	}

	private static function applyJobResultToActivity(
		int $activityId,
		FillRepeatSaleTipsPayload $payload,
		?Context $context,
	): bool
	{
		if ($payload->noRecommendation)
		{
			self::logDescriptionKeptForEmptyAnswer($activityId);
		}
		elseif (!self::rewriteDescriptionFromPayload($activityId, $payload, $context))
		{
			return false;
		}

		self::cleanBadgeByType($activityId, Badge\Badge::AI_FIELDS_FILLING_RESULT);
		self::notifyTimelinesAboutActivityUpdate($activityId);

		return true;
	}

	private static function rewriteDescriptionFromPayload(
		int $activityId,
		FillRepeatSaleTipsPayload $payload,
		?Context $context,
	): bool
	{
		$isUpdated = (bool)CCrmActivity::Update($activityId, [
			'DESCRIPTION' => RepeatSale::createDescriptionFromPayload(
				$payload,
				false,
				$context?->getLanguage()?->getCode(),
			),
		]);
		if (!$isUpdated)
		{
			AIManager::logger()->error(
				'{date}: {class}: Error while trying to save activity of job error: {activityId}' . PHP_EOL,
				[
					'class' => self::class,
					'activityId' => $activityId,
				],
			);
		}

		return $isUpdated;
	}

	/** The card falls back to the segment prompt while the description is empty, so an empty answer keeps it. */
	private static function logDescriptionKeptForEmptyAnswer(int $activityId): void
	{
		AIManager::logger()->info(
			'{date}: {class}: The AI answer has nothing to recommend for activity {activityId},'
			. ' its description is left untouched' . PHP_EOL,
			[
				'class' => self::class,
				'activityId' => $activityId,
			],
		);
	}

	/**
	 * An empty answer finishes the job successfully, so the generic status would hide it. Its own
	 * status takes the place of the critical records that used to be its only sign in monitoring.
	 */
	protected static function enrichJobFinishEventBuilder(AIBaseEvent $builder, Result $result): void
	{
		$payload = $result->getPayload();
		if ($payload instanceof FillRepeatSaleTipsPayload && $payload->noRecommendation)
		{
			$builder->setStatus(\Bitrix\Crm\Integration\Analytics\Dictionary::STATUS_REPEAT_SALE_NO_TIPS);
		}
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
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
		}
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new FillRepeatSaleTipsEvent();
	}
}
