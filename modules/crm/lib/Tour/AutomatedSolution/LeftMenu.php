<?php

namespace Bitrix\Crm\Tour\AutomatedSolution;

use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Localization\Loc;

class LeftMenu extends Base
{
	protected const OPTION_NAME = 'aha-moment-automated-solution-left-menu';

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
				'id' => 'crm-automated-solution-left-menu-step',
				'target' => '#' . $this->targetId,
				'title' => Loc::getMessage('CRM_TOUR_AUTOMATED_SOLUTION_LEFT_MENU_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_AUTOMATED_SOLUTION_LEFT_MENU_TEXT'),
				'position' => 'right',
				'highlighter' => [
					'target' => '#' . $this->targetId,
					'radius' => 10,
				],
				'autoscroll' => [
					'behavior' => 'smooth', // smooth|auto
					'position' => 'end', // top|center|end|nearest,
				],
				'buttons' => [
					[
						'text' => Loc::getMessage('CRM_TOUR_AUTOMATED_SOLUTION_LEFT_MENU_BUTTON'),
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
					'width' => 400,
				],
			],
		];
	}
}
