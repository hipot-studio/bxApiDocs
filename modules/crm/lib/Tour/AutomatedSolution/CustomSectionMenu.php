<?php

namespace Bitrix\Crm\Tour\AutomatedSolution;

use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Localization\Loc;

class CustomSectionMenu extends Base
{
	protected const OPTION_NAME = 'aha-moment-automated-solution-custom-section-menu';

	private string $targetId = '';

	protected function canShow(): bool
	{
		return !$this->isUserSeenTour();
	}

	public function setTargetId(string $targetId): self
	{
		$this->targetId = $targetId;

		return $this;
	}

	protected function getSteps(): array
	{
		return [
			[
				'id' => 'crm-automated-solution-custom-section-menu-step',
				'target' => '#' . $this->targetId,
				'title' => Loc::getMessage('CRM_TOUR_AUTOMATED_SOLUTION_CUSTOM_SECTION_MENU_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_AUTOMATED_SOLUTION_CUSTOM_SECTION_MENU_TEXT'),
				'buttons' => [
					[
						'text' => Loc::getMessage('CRM_TOUR_AUTOMATED_SOLUTION_CUSTOM_SECTION_MENU_BUTTON'),
						'onclick' => [
							'closeAfterClick' => true,
						],
					],
				],
			],
		];
	}

	public function getOptions(): array
	{
		return [
			'steps' => [
				'popup' => [
					'width' => 380,
				],
			],
		];
	}
}
