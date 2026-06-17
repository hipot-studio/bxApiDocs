<?php

namespace Bitrix\Crm\RepeatSale\Schedule;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\RepeatSale\AvailabilityChecker;
use Bitrix\Crm\RepeatSale\Job\Entity\RepeatSaleJobTable;
use Bitrix\Crm\RepeatSale\Queue\Controller\RepeatSaleQueueController;
use Bitrix\Crm\RepeatSale\Queue\QueueItem;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\RepeatSale\Segment\Entity\RepeatSaleSegment;
use Bitrix\Crm\RepeatSale\Segment\SegmentCode;
use Bitrix\Crm\RepeatSale\Segment\SegmentItem;
use Bitrix\Crm\RepeatSale\Service\Handler\AiApproveHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\AiScreeningHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\ConfigurableHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\HandlerType;
use Bitrix\Crm\RepeatSale\Service\Handler\RemainingHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\SystemHandler;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Objectify\Collection;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\Type\Date;

final class Scheduler
{
	use Singleton;

	private bool $isOnlyCalc = false;

	public function execute(): void
	{
		$availabilityChecker = Container::getInstance()->getRepeatSaleAvailabilityChecker();
		if (!$availabilityChecker->isEnabled() || !$availabilityChecker->isItemsCountsLessThenLimit())
		{
			return;
		}

		$params = [
			'date' => (new Date())->getTimestamp(),
		];

		$queueController = RepeatSaleQueueController::getInstance();

		$jobs = $this->getSuitableJobs();
		foreach ($jobs as $job)
		{
			$segmentCode = $job->getSegment()->getCode();
			$itemParams = [
				'segmentCode' => $segmentCode,
				'segmentId' => $job->getSegmentId(),
			];

			$handlerTypeId = $this->getHandlerTypeId($segmentCode);

			if ($this->isOnlyCalc && $handlerTypeId !== SystemHandler::getTypeValue())
			{
				continue;
			}

			if (HandlerType::isAiHandler(HandlerType::fromValue($handlerTypeId)))
			{
				$isDisabledSegment = $this->tryDisableSegment($availabilityChecker, $job->getSegment(), $handlerTypeId);

				if ($isDisabledSegment)
				{
					continue;
				}
			}

			$queueItem = QueueItem::createFromArray([
				'jobId' => $job->getId(),
				'params' => array_merge($params, $itemParams),
				'isOnlyCalc' => $this->isOnlyCalc,
				'handlerTypeId' => $handlerTypeId,
			]);

			$queueController->add($queueItem);

			$this->sendAnalytics();
		}

		if ($this->isOnlyCalc)
		{
			Option::delete('crm', ['name' => 'repeat-sale-wait-only-calc-scheduler']);
		}
	}

	public function setOnlyCalc(bool $value = true): self
	{
		$this->isOnlyCalc = $value;

		return $this;
	}

	/**
	 * @return Collection
	 */
	private function getSuitableJobs(): Collection
	{
		$filter = [
			'=SEGMENT.BASE_SEGMENT_CODE' => null,
		];
		if (!$this->isOnlyCalc)
		{
			$filter['SEGMENT.IS_ENABLED'] = 'Y';
		}

		$query = RepeatSaleJobTable::query()
			->setSelect(['ID', 'SEGMENT_ID', 'SEGMENT.*'])
			->setFilter($filter)
		;

		// remaining segment must run after the holiday (ai_screening) one,
		// so any-purchase processing happens only after holiday screening.
		$caseExpression = new ExpressionField(
			'SORT_ORDER',
			"CASE
				WHEN %s = '" . SegmentCode::REMAINING->value . "' THEN 3
				WHEN %s = '" . SegmentCode::AI_SCREENING->value . "' THEN 2
				ELSE 1
			END",
			['SEGMENT.CODE', 'SEGMENT.CODE'],
		);

		$query
			->registerRuntimeField('SORT_ORDER', $caseExpression)
			->setOrder(['SORT_ORDER' => 'ASC'])
		;

		return QueryHelper::decompose($query);
	}

	private function sendAnalytics(): void
	{
		$event = new AnalyticsEvent('rs-add-queue-item', Dictionary::TOOL_CRM, Dictionary::CATEGORY_SYSTEM_INFORM);

		try
		{
			$event
				->setType(Dictionary::TYPE_AGENT)
				->send()
			;
		}
		catch (\Exception $e)
		{

		}
	}

	public function addChildrenJobsToQueueIfNotExists(int $parentSegmentId): void
	{
		$segmentController = RepeatSaleSegmentController::getInstance();
		$parentSegment = $segmentController->getById($parentSegmentId);

		if (!$parentSegment)
		{
			return;
		}

		$systemSegments = $segmentController->getList([
			'select' => ['ID', 'CODE', 'JOB.ID'],
			'filter' => [
				'IS_SYSTEM' => 'Y',
				'BASE_SEGMENT_CODE' => $parentSegment->getCode(),
			],
		]);

		$params = [
			'date' => (new Date())->getTimestamp(),
		];

		$queueController = RepeatSaleQueueController::getInstance();

		foreach ($systemSegments as $systemSegment)
		{
			$segmentCode = $systemSegment->getCode();
			$itemParams = [
				'segmentCode' => $segmentCode,
				'segmentId' => $systemSegment->getId(),
			];

			$queueItem = QueueItem::createFromArray([
				'jobId' => $systemSegment->getJob()->getId(),
				'params' => array_merge($params, $itemParams),
				'isOnlyCalc' => $this->isOnlyCalc,
				'handlerTypeId' => $this->getHandlerTypeId($segmentCode),
			]);

			$queueController->add($queueItem);
		}
	}

	private function getHandlerTypeId(?string $segmentCode): string
	{
		if ($segmentCode === SegmentCode::AI_SCREENING->value)
		{
			return AiScreeningHandler::getTypeValue();
		}

		if ($segmentCode === SegmentCode::AI_APPROVE->value)
		{
			return AiApproveHandler::getTypeValue();
		}

		if ($segmentCode === SegmentCode::REMAINING->value)
		{
			return RemainingHandler::getTypeValue();
		}

		if ($segmentCode === null)
		{
			return ConfigurableHandler::getTypeValue();
		}

		return SystemHandler::getTypeValue();
	}

	private function tryDisableSegment(
		AvailabilityChecker $availabilityChecker,
		RepeatSaleSegment $segmentEntity,
		string $handlerTypeId,
	): bool
	{
		if (
			Feature::enabled(Feature\RepeatSaleAiSegment::class)
			&& $availabilityChecker->isAiSegmentsAvailable()
			&& $handlerTypeId !== RemainingHandler::getTypeValue()
		)
		{
			return false;
		}

		if (
			Feature::enabled(Feature\RepeatSaleRemainingSegment::class)
			&& $availabilityChecker->isAiSegmentsAvailable()
			&& $handlerTypeId === RemainingHandler::getTypeValue()
		)
		{
			return false;
		}

		$segment = SegmentItem::createFromEntity($segmentEntity);
		$segment->setIsEnabled(false);

		$result = RepeatSaleSegmentController::getInstance()->update($segment->getId(), $segment);

		return $result->isSuccess();
	}
}
