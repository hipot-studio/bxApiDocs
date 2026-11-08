<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Public\Features\PublicLinksControl;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Config\Feature;

/**
 * Control of public links across the portal. Unlike other toggler rights, a portal without
 * the rights tariff option grants it to administrators only.
 */
class ShareControlRule extends BaseRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if (!Feature::isEnabled(PublicLinksControl::class))
		{
			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		if (!$this->isRightsFeatureEnabled())
		{
			return false;
		}

		return parent::execute($item, $params);
	}
}
