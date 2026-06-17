<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature;
use Bitrix\Crm\RepeatSale\Logger;
use Bitrix\Crm\RepeatSale\Segment\FillPreliminarySegments;
use Bitrix\Main\Localization\Loc;

class RepeatSaleRemainingSegment extends BaseFeature
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_REPEAT_SALE_REMAINING_SEGMENT_NAME');
	}

	protected function getOptionName(): string
	{
		return 'CRM_FEATURE_REPEAT_SALE_REMAINING_SEGMENT';
	}

	public function getCategory(): Category\RepeatSale
	{
		return Category\RepeatSale::getInstance();
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
			(new Logger())->info('RepeatSaleSegment feature not enabled, because the repeat sales feature is not enabled on the portal', []);

			return;
		}

		if (!Feature::enabled(Feature\RepeatSaleAiSegment::class))
		{
			(new Logger())->info('RepeatSaleAiSegment feature not enabled, because the repeat sales ai segment feature is not enabled on the portal', []);

			return;
		}

		parent::enable();

		(new FillPreliminarySegments())->setAdminAsAssignmentUser()->execute();
	}
}
