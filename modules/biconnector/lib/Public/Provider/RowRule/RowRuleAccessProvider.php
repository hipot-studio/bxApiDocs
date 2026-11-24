<?php

namespace Bitrix\BIConnector\Public\Provider\RowRule;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Services\RowRule\RowRuleError;
use Bitrix\Main\Result;

/**
 * Whether a user may manage row rules: the feature is on and the user may enter BI Builder,
 * holds the right to the analyst workspace and the right to the rules.
 */
class RowRuleAccessProvider
{
	public function canManage(int $userId): bool
	{
		return $this->check($userId)->isSuccess();
	}

	/**
	 * @param int $userId
	 *
	 * @return Result ROW_RULE_FEATURE_DISABLED when the feature is off,
	 * then ROW_RULE_ACCESS_DENIED without any of the rights.
	 */
	public function check(int $userId): Result
	{
		$result = new Result();

		if (!Feature::isRowRulesEnabled())
		{
			return $result->addError(RowRuleError::create(RowRuleError::FEATURE_DISABLED));
		}

		$accessController = $userId > 0 ? AccessController::getInstance($userId) : null;
		// The workspace right does not imply the access to BI Builder: both are checked.
		$canManage = $accessController
			&& $accessController->check(ActionDictionary::ACTION_BIC_ACCESS)
			&& $accessController->check(ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG)
			&& $accessController->check(ActionDictionary::ACTION_BIC_SETTINGS_EDIT_ROW_RULES)
		;
		if (!$canManage)
		{
			return $result->addError(RowRuleError::create(RowRuleError::ACCESS_DENIED));
		}

		return $result;
	}
}
