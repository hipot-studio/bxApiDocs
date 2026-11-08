<?php

namespace Bitrix\BIConnector\Access\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Requires the key management operation in every scope of the controller: the REST contour has no other
 * guard than the authorization type, and the ajax contour has none at all.
 */
final class KeyManageAccess extends Base
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';

	private const KEY_MANAGE_OPERATION = 'biconnector_key_manage';

	public function onBeforeAction(Event $event): ?EventResult
	{
		if (CurrentUser::get()->canDoOperation(self::KEY_MANAGE_OPERATION) === false)
		{
			$this->addError(new Error('Access denied', self::ERROR_ACCESS_DENIED));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
