<?php

namespace Bitrix\Crm\Tour;

use Bitrix\Main\Localization\Loc;

class ConfigPermsUserSelector extends Base
{
	public const OPTION_NAME = 'aha-moment-config-perms-user-selector';

	protected function canShow(): bool
	{
		return !$this->isUserSeenTour();
	}

	protected function getSteps(): array
	{
		return [
			[
				'id' => self::OPTION_NAME,
				'text' => Loc::getMessage('CRM_TOUR_CONFIG_PERMS_USER_SELECTOR_MESSAGE_TEXT'),
				'target' => '.ui-access-rights-v2-user-selector',
			],
		];
	}

	protected function getOptions(): array
	{
		return [
			'hideTourOnMissClick' => true,
			'steps' => [
				'popup' => [
					'width' => 230,
				],
			],
		];
	}
}