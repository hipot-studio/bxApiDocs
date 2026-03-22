<?php

namespace Bitrix\Crm\RepeatSale\Segment;

use Bitrix\Crm\Feature;
use Bitrix\Crm\RepeatSale\Logger;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

final class FillPreliminarySegments
{
	private Logger $logger;

	public function __construct()
	{
		$this->logger = new Logger();
	}

	public function execute(): void
	{
		$controller = RepeatSaleSegmentController::getInstance();

		$addedSegments = [];

		$collection = $controller->getList();
		$codes = $collection->getCodeList();

		foreach ($this->getData() as $data)
		{
			if (in_array($data['code'], $codes, true))
			{
				$this->logger->debug(
					'Segment exist',
					[
						'segmentCode' => $data['code'],
					],
				);

				continue;
			}

			$segment = SegmentItem::createFromArray($data);
			$result = $controller->add($segment);
			if ($result->isSuccess())
			{
				$addedSegments[] = $segment->getCode();
			}
			else
			{
				$this->logger->error(
					'Segment not added',
					[
						'segmentCode' => $segment->getCode(),
					],
				);
			}
		}

		$this->logger->info('Segments have been added', $addedSegments);
	}

	private function getData(): array
	{
		$entityTypeId = CCrmOwnerType::Deal;
		$factory = Container::getInstance()->getFactory($entityTypeId);

		$fieldRepository = ServiceLocator::getInstance()->get('crm.model.fieldRepository');
		$resolver = $fieldRepository->getDefaultStageIdResolver($entityTypeId);

		$params = [
			'isEnabled' => Feature::enabled(Feature\RepeatSaleForceMode::class),
			'entityTypeId' => $entityTypeId,
			'entityCategoryId' => $factory?->getDefaultCategory()?->getId() ?? 0,
			'entityStageId' => $resolver(),
			'callAssessmentId' => null,
			'isAiEnabled' => true,
			'assignmentUserIds' => [],
			'isSystem' => false,
		];

		$segments = [
			//$this->getSleepingClients($params),
			//$this->getLostClients($params),
			$this->getEveryYearDeals($params),
			$this->getEveryHalfYearDeals($params),
			$this->getEveryMonthDeals($params),
		];

		if (Feature::enabled(Feature\RepeatSaleAiSegment::class))
		{
			$segments[] = $this->getAiScreeningDeals($params);
			$segments[] = $this->getAiApproveDeals($params);
		}

		return $segments;
	}

	private function getLostClients(array $params): array
	{
		$data = [
			'code' => SegmentCode::LOST_CLIENT->value,
			'title' => Loc::getMessage('CRM_FPS_LESS_12MONTH_TITLE'),
			'prompt' => Loc::getMessage('CRM_FPS_LESS_12MONTH_PROMPT'),
			'entityTitlePattern' => Loc::getMessage('CRM_FPS_LESS_12MONTH_ENTITY_TITLE_PATTERN'),
			'isAiEnabled' => false,
		];

		return array_merge($params, $data);
	}

	private function getSleepingClients(array $params): array
	{
		$data = [
			'code' => SegmentCode::SLEEPING_CLIENT->value,
			'title' => Loc::getMessage('CRM_FPS_12MONTH_TITLE'),
			'prompt' => Loc::getMessage('CRM_FPS_12MONTH_PROMPT'),
			'entityTitlePattern' => Loc::getMessage('CRM_FPS_12MONTH_ENTITY_TITLE_PATTERN'),
			'isAiEnabled' => false,
		];

		return array_merge($params, $data);
	}

	private function getEveryYearDeals(array $params): array
	{
		$data = [
			'code' => SegmentCode::DEAL_EVERY_YEAR->value,
			'title' => Loc::getMessage('CRM_FPS_EVERY_YEAR_TITLE'),
			'prompt' => Loc::getMessage('CRM_FPS_EVERY_YEAR_PROMPT'),
			'entityTitlePattern' => Loc::getMessage('CRM_FPS_EVERY_YEAR_ENTITY_TITLE_PATTERN'),
		];

		return array_merge($params, $data);
	}

	private function getEveryHalfYearDeals(array $params): array
	{
		$data = [
			'code' => SegmentCode::DEAL_EVERY_HALF_YEAR->value,
			'title' => Loc::getMessage('CRM_FPS_EVERY_HALF_YEAR_TITLE'),
			'prompt' => Loc::getMessage('CRM_FPS_EVERY_HALF_YEAR_PROMPT'),
			'entityTitlePattern' => Loc::getMessage('CRM_FPS_EVERY_HALF_YEAR_ENTITY_TITLE_PATTERN'),
		];

		return array_merge($params, $data);
	}

	private function getEveryMonthDeals(array $params): array
	{
		$data = [
			'code' => SegmentCode::DEAL_EVERY_MONTH->value,
			'title' => Loc::getMessage('CRM_FPS_EVERY_MONTH_TITLE'),
			'prompt' => Loc::getMessage('CRM_FPS_EVERY_MONTH_PROMPT'),
			'entityTitlePattern' => Loc::getMessage('CRM_FPS_EVERY_MONTH_ENTITY_TITLE_PATTERN'),
		];

		return array_merge($params, $data);
	}

	private function getAiScreeningDeals(array $params): array
	{
		$data = [
			'isEnabled' => $this->isAiSegmentsAvailable(),
			'code' => SegmentCode::AI_SCREENING->value,
			'title' => Loc::getMessage('CRM_FPS_AI_SCREENING_TITLE'),
			'prompt' => Loc::getMessage('CRM_FPS_AI_SCREENING_PROMPT'),
			'entityTitlePattern' => Loc::getMessage('CRM_FPS_AI_SCREENING_ENTITY_TITLE_PATTERN'),
		];

		return array_merge($params, $data);
	}

	private function getAiApproveDeals(array $params): array
	{
		$data = [
			'isEnabled' => $this->isAiSegmentsAvailable(),
			'code' => SegmentCode::AI_APPROVE->value,
			'title' => Loc::getMessage('CRM_FPS_AI_APPROVE_TITLE'),
			'prompt' => Loc::getMessage('CRM_FPS_AI_APPROVE_PROMPT'),
			'entityTitlePattern' => Loc::getMessage('CRM_FPS_AI_APPROVE_ENTITY_TITLE_PATTERN'),
			'isSystem' => true,
			'baseSegmentCode' => SegmentCode::AI_SCREENING->value,
		];

		return array_merge($params, $data);
	}

	private function isAiSegmentsAvailable(): bool
	{
		return Container::getInstance()->getRepeatSaleAvailabilityChecker()->isAiSegmentsAvailable();
	}
}
