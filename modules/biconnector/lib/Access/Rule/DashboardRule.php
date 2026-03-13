<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Model\DashboardAccessItem;

class DashboardRule extends VariableRule
{
	/**
	 * Check access permission.
	 *
	 * @param array $params
	 *
	 * @return bool
	 */
	public function check(array $params): bool
	{
		$item = $params['item'] ?? null;
		if ($item instanceof DashboardAccessItem)
		{
			if ($this->isAbleToSkipChecking())
			{
				return true;
			}

			return parent::check($params);
		}
		if ($item !== null)
		{
			return false;
		}

		return parent::check($params);
	}
}
