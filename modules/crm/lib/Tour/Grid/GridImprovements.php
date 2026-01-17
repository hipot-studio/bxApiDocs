<?php

namespace Bitrix\Crm\Tour\Grid;

use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Localization\Loc;

class GridImprovements extends Base
{
	public const EVENT_NAME = 'BX.Crm.Tour.Grid.GridImprovements:userCellInSight';
	public const USER_CELL_SELECTOR = '.crm-grid-user-wrapper';
	public const GRID_SELECTOR = '.main-grid-container';
	public const MAIN_STEP_ID = 'grid-improvements-message';
	protected const OPTION_NAME = 'grid-improvements';

	protected function canShow(): bool
	{
		return !$this->isUserSeenTour();
	}

	protected function getSteps(): array
	{
		return [
			[
				'id' => self::MAIN_STEP_ID,
				'title' => Loc::getMessage('CRM_TOUR_GRID_IMPROVEMENTS_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_GRID_IMPROVEMENTS_TEXT'),
				'position' => 'top',
				'useDynamicTarget' => true,
				'eventName' => self::EVENT_NAME,
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

	protected function getComponentTemplate(): string
	{
		return 'grid_improvements';
	}
}
