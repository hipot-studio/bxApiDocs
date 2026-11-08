<?php

namespace Bitrix\Crm\Tour;

use Bitrix\Main\Localization\Loc;

final class StageDescription extends Base
{
	public const OPTION_NAME = 'stage-description';

	protected function canShow(): bool
	{
		return !$this->isUserSeenTour();
	}

	protected function getSteps(): array
	{
		return [
			[
				'id' => self::OPTION_NAME,
				'title' => Loc::getMessage('CRM_TOUR_STAGE_DESCRIPTION_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_STAGE_DESCRIPTION_TEXT'),
				'position' => 'top',
				'target' => '#crm_kanban .main-kanban-column:first-child .main-kanban-column-title',
			],
		];
	}

	protected function getOptions(): array
	{
		return [
			'hideTourOnMissClick' => true,
			'steps' => [
				'popup' => [
					'width' => 350,
				],
			],
		];
	}
}
