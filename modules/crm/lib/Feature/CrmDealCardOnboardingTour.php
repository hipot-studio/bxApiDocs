<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Main\Localization\Loc;

final class CrmDealCardOnboardingTour extends AbstractCrmFlag
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_CRM_DEAL_CARD_ONBOARDING_TOUR_NAME');
	}
}
