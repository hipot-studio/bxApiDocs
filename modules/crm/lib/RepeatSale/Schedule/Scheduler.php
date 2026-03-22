<?php

namespace Bitrix\Crm\RepeatSale\Schedule;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\RepeatSale\AvailabilityChecker;
use Bitrix\Crm\RepeatSale\Job\Controller\RepeatSaleJobController;
use Bitrix\Crm\RepeatSale\Queue\Controller\RepeatSaleQueueController;
use Bitrix\Crm\RepeatSale\Queue\QueueItem;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\RepeatSale\Segment\Entity\RepeatSaleSegment;
use Bitrix\Crm\RepeatSale\Segment\SegmentCode;
use Bitrix\Crm\RepeatSale\Segment\SegmentItem;
use Bitrix\Crm\RepeatSale\Service\Handler\AiApproveHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\AiScreeningHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\ConfigurableHandler;
use Bitrix\Crm\RepeatSale\Service\Handler\SystemHandler;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Objectify\Collection;
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

			if (
				$handlerTypeId === AiScreeningHandler::getTypeValue()
				|| $handlerTypeId === AiApproveHandler::getTypeValue()
			)
			{
				$isDisableSegment = $this->tryDisableSegment($availabilityChecker, $job->getSegment());

				if ($isDisableSegment)
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
		$params = [
			'filter' => [
				'=SEGMENT.BASE_SEGMENT_CODE' => null,
			],
		];
		if (!$this->isOnlyCalc)
		{
			$params['filter'][] = [
				'SEGMENT.IS_ENABLED' => 'Y',
			];
		}

		return RepeatSaleJobController::getInstance()->getList($params);
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

		if ($segmentCode === null)
		{
			return ConfigurableHandler::getTypeValue();
		}

		return SystemHandler::getTypeValue();
	}

	private function tryDisableSegment(
		AvailabilityChecker $availabilityChecker,
		RepeatSaleSegment $segmentEntity,
	): bool
	{
		if (
			Feature::enabled(Feature\RepeatSaleAiSegment::class)
			&& $availabilityChecker->isAiSegmentsAvailable()
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
