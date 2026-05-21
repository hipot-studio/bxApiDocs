<?php

namespace Bitrix\Crm\Tour\RepeatSale;

use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Localization\Loc;

final class ConfigureSegment extends Base
{
	private static ?bool $canShowCached = null;

	protected const OPTION_NAME = 'copilot-repeat-sale-start';
	private const REPEAT_SALE_BUTTON_SELECTOR = '.crm-repeat-sale-btn';

	public function isGlowingSettingsButton(): bool
	{
		return $this->canShow();
	}

	protected function canShow(): bool
	{
		if (self::$canShowCached === null)
		{
			self::$canShowCached = (
				!$this->isUserSeenTour()
				&& Container::getInstance()->getRepeatSaleAvailabilityChecker()->isAvailable()
				&& $this->hasPermissionToEditSegments()
				&& $this->hasRepeatSaleDeals()
			);
		}

		return self::$canShowCached;
	}

	protected function getSteps(): array
	{
		return [
			[
				'id' => 'configure-repeat-sale-segment-tour',
				'title' => Loc::getMessage('CRM_TOUR_REPEAT_SALE_CONFIGURE_SEGMENT_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_REPEAT_SALE_CONFIGURE_SEGMENT_TEXT'),
				'position' => 'bottom',
				'target' => self::REPEAT_SALE_BUTTON_SELECTOR,
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

	private function hasRepeatSaleDeals(): bool
	{
		$collection = RepeatSaleSegmentController::getInstance()->getList([
			'select' => ['CLIENT_FOUND'],
			'filter' => ['>CLIENT_FOUND' => 0, 'IS_ENABLED' => 'Y'],
			'limit' => 1,
		]);

		return !$collection->isEmpty();
	}

	private function hasPermissionToEditSegments(): bool
	{
		return Container::getInstance()->getUserPermissions()->repeatSale()->canEdit();
	}
}
