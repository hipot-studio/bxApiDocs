<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature;
use Bitrix\Crm\RepeatSale\Logger;
use Bitrix\Main\Localization\Loc;

class RepeatSaleAiSegment extends BaseFeature
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_REPEAT_SALE_AI_SEGMENT_NAME');
	}

	protected function getOptionName(): string
	{
		return 'CRM_FEATURE_REPEAT_SALE_AI_SEGMENT';
	}

	protected function getEnabledValue(): bool
	{
		return true;
	}

	public function enable(): void
	{
		if ($this->isEnabled())
		{
			return;
		}

		if (!Feature::enabled(Feature\RepeatSale::class))
		{
			(new Logger())->info('RepeatSaleAiSegment feature not enabled, because the repeat sales feature is not enabled on the portal', []);

			return;
		}

		parent::enable();

		/**
		 * @see \Bitrix\Crm\Agent\RepeatSale\PrefillAgent
		 */
		\CAgent::AddAgent(
			'Bitrix\Crm\Agent\RepeatSale\PrefillAgent::run();',
			'crm',
			'N',
			60,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 50, 'FULL'),
		);
	}
}
