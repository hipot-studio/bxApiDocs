<?php

namespace Bitrix\Crm\Controller\RepeatSale;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\ErrorCode as AIErrorCode;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\Sandbox\SandboxManager;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\RepeatSale\Segment\SegmentCode;
use Bitrix\Crm\RepeatSale\Segment\SegmentItem;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;

if (!Loader::includeModule('crm'))
{
	return;
}

final class Sandbox extends \Bitrix\Crm\Controller\RepeatSale\Base
{
	public function sendToAiAction(
		ItemIdentifier $clientIdentifier,
		ItemIdentifier $dealIdentifier,
		int $segmentId,
	): ?Result
	{
		if (!AIManager::isAvailable())
		{
			$this->addError(new Error('Ai module is not installed or region is not available.'));

			return null;
		}

		$this->cleanOldQueueItems($dealIdentifier);

		$userId = $this->getCurrentUser()?->getId();

		if (!Scenario::isEnabledScenario(Scenario::REPEAT_SALE_TIPS_SCENARIO))
		{
			$error = AIErrorCode::getAIDisabledError([
				'sliderCode' => Scenario::REPEAT_SALE_TIPS_SCENARIO_SLIDER_CODE,
			]);
			$this->addError($error);

			return null;
		}

		return AIManager::launchSandboxFillRepeatSaleTips($dealIdentifier, $clientIdentifier, $segmentId, $userId);
	}

	private function cleanOldQueueItems(ItemIdentifier $dealIdentifier): void
	{
		$typeIds = [
			\Bitrix\Crm\Integration\AI\Operation\Sandbox\FillRepeatSaleTips::TYPE_ID,
			\Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem::TYPE_ID,
		];

		foreach ($typeIds as $typeId)
		{
			QueueTable::deleteByItem($dealIdentifier, $typeId);
		}
	}

	public function checkItemAction(
		ItemIdentifier $clientIdentifier,
		ItemIdentifier $dealIdentifier,
		int $segmentId,
		int $date,
	): array
	{
		$segmentController = RepeatSaleSegmentController::getInstance();
		$segmentItem = SegmentItem::createFromEntity($segmentController->getById($segmentId));

		$isSuitableItem = SandboxManager::getInstance()
			->isSuitableItem(
				$segmentItem,
				$dealIdentifier,
				$clientIdentifier,
				(new Date())::createFromTimestamp($date),
			)
		;

		return [
			'isSuitableItem' => $isSuitableItem,
		];
	}

	public function checkPeriodAction(
		int $segmentId,
		int $fromDate,
		int $toDate,
	): array
	{
		$segmentController = RepeatSaleSegmentController::getInstance();
		$segmentItem = SegmentItem::createFromEntity($segmentController->getById($segmentId));

		$items = SandboxManager::getInstance()
			->getSuitableItems(
				$segmentItem,
				(new Date())::createFromTimestamp($fromDate),
				(new Date())::createFromTimestamp($toDate),
			)
		;

		return [
			'items' => $items,
		];
	}

	public function getMarkersAction(
		ItemIdentifier $clientIdentifier,
		ItemIdentifier $itemIdentifier,
		int $segmentId,
	): array {
		$userId = $this->getCurrentUser()?->getId();

		// @todo There is no need to exclude $itemIdentifier from markers for feature ai_segment

		return PayloadFactory::build(FillRepeatSaleTips::TYPE_ID, $userId, $itemIdentifier)
			->setSandboxData([
				'segmentId' => $segmentId,
				'clientEntityTypeId' => $clientIdentifier->getEntityTypeId(),
				'clientEntityId' => $clientIdentifier->getEntityId(),
			])
			->setMarkers([])
			->getResult()
			->getData()['payload']
			->getMarkers()
		;
	}

	public function getAutoWiredParameters(): array
	{
		$params = parent::getAutoWiredParameters();

		$params[] = new ExactParameter(
			ItemIdentifier::class,
			'dealIdentifier',
			static function ($className, $entityTypeId, $entityId) {
				return new $className($entityTypeId, $entityId);
			},
		);

		$params[] = new ExactParameter(
			ItemIdentifier::class,
			'clientIdentifier',
			static function ($className, $clientTypeId, $clientId) {
				return new $className($clientTypeId, $clientId);
			},
		);

		return $params;
	}

	public function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();

		$filters[] = new class() extends Base {
			public function onBeforeAction(Event $event): ?EventResult
			{
				if (
					!Feature::enabled(Feature\RepeatSaleSandbox::class)
					|| !Container::getInstance()->getUserPermissions()->isAdmin()
				)
				{
					$this->addError(ErrorCode::getAccessDeniedError());

					return new EventResult(type: EventResult::ERROR, handler: $this);
				}

				$arguments = $this->action->getArguments();
				$segmentId = $arguments['segmentId'] ?? null;
				if ($segmentId === null)
				{
					return null;
				}

				$segment = RepeatSaleSegmentController::getInstance()->getById($segmentId);
				if (SegmentCode::isNeedAiModule($segment->getCode()) && !AIManager::isAvailable())
				{
					$this->addError(new Error('Ai module is not installed or region is not available.'));

					return new EventResult(type: EventResult::ERROR, handler: $this);
				}

				return null;
			}
		};

		return $filters;
	}
}
