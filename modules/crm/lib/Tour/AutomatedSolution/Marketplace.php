<?php

namespace Bitrix\Crm\Tour\AutomatedSolution;

use Bitrix\Crm\Integration\Market\Router;
use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Localization\Loc;

class Marketplace extends Base
{
	protected const OPTION_NAME = 'aha-moment-automated-solution-marketplace';

	protected function canShow(): bool
	{
		return !$this->isUserSeenTour();
	}

	protected function getSteps(): array
	{
		$marketUrl = Router::getCategoryPath('automated_solutions_seats');

		return [
			[
				'id' => 'crm-automated-solution-marketplace-step',
				'target' => '#automated_solution-list-toolbar-marketplace-button',
				'title' => Loc::getMessage('CRM_TOUR_AUTOMATED_SOLUTION_MARKETPLACE_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_AUTOMATED_SOLUTION_MARKETPLACE_TEXT'),
				'buttons' => [
					[
						'text' => Loc::getMessage('CRM_TOUR_AUTOMATED_SOLUTION_MARKETPLACE_BUTTON'),
						'onclick' => [
							'code' => "BX.SidePanel.Instance.open('" . $marketUrl . "')",
							'closeAfterClick' => true,
						],
					],
				],
				'iconSrc' => '/bitrix/images/crm/whats_new/automated_solution/marketplace.png',
			],
		];
	}

	public function getOptions(): array
	{
		return [
			'steps' => [
				'popup' => [
					'width' => 524,
				],
			],
		];
	}
}
