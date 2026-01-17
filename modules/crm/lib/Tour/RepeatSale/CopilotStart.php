<?php

namespace Bitrix\Crm\Tour\RepeatSale;

use Bitrix\Crm\Activity\Provider\RepeatSale;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\EO_Activity;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\FillRepeatSaleTipsPayload;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\Type\CopilotButtonRepeatSale;
use Bitrix\Crm\Tour\Base;
use Bitrix\Crm\Tour\Mixin\HasEntitySupport;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

final class CopilotStart extends Base
{
	use HasEntitySupport;

	protected const OPTION_NAME = 'copilot-repeat-sale-start';

	private ?EO_Activity $activity = null;

	protected function canShow(): bool
	{
		$this->activity = $this->getActivity();

		return (
			!$this->isUserSeenTour()
			&& $this->entityTypeId === CCrmOwnerType::Deal
			&& $this->entityId > 0
			&& AIManager::isAiCallProcessingEnabled()
			&& Container::getInstance()->getRepeatSaleAvailabilityChecker()->isEnabled()
			&& $this->activity !== null
		);
	}

	protected function getSteps(): array
	{
		/** @var Result<FillRepeatSaleTipsPayload>|null */
		$operationState = JobRepository::getInstance()->getFillRepeatSaleTipsByActivity($this->activity?->getId() ?? 0);
		$textCode = $operationState?->isSuccess()
			? 'CRM_TOUR_COPILOT_REPEAT_SALE_TEXT_AUTOMATICALLY'
			: 'CRM_TOUR_COPILOT_REPEAT_SALE_TEXT_MANUALLY'
		;

		return [
			[
				'id' => 'copilot-repeat-sale-start',
				'title' => Loc::getMessage('CRM_TOUR_COPILOT_REPEAT_SALE_TITLE'),
				'text' => Loc::getMessage($textCode),
				'position' => 'top',
				'target' => sprintf('#%s', CopilotButtonRepeatSale::BUTTON_TARGET_ID),
				'article' => 25376986,
			],
		];
	}

	protected function getOptions(): array
	{
		return [
			'hideTourOnMissClick' => true,
			'steps' => [
				'popup' => [
					'width' => 400,
				],
			],
		];
	}

	private function getActivity(): ?EO_Activity
	{
		return ActivityTable::query()
			->setSelect(['ID'])
			->where('OWNER_TYPE_ID',$this->entityTypeId)
			->where('OWNER_ID', $this->entityId)
			->where('PROVIDER_ID', RepeatSale::PROVIDER_ID)
			->setLimit(1)
			->fetchObject()
		;
	}
}
