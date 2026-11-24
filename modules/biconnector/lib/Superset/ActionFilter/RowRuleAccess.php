<?php

namespace Bitrix\BIConnector\Superset\ActionFilter;

use Bitrix\BIConnector\Public\Provider\RowRule\RowRuleAccessProvider;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Lets an action of the row rules run only while the feature is on and the current user holds the right.
 */
final class RowRuleAccess extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		$result = (new RowRuleAccessProvider())->check((int)CurrentUser::get()->getId());
		if ($result->isSuccess())
		{
			return null;
		}

		foreach ($result->getErrors() as $error)
		{
			$this->addError($error);
		}

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
