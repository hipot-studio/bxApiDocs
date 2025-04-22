<?php declare(strict_types=1);

namespace Bitrix\AI\Controller\ActionFilter;

use Bitrix\AI\Facade\User;
use Bitrix\Intranet\Util;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Error;
use Bitrix\Extranet\Service\ServiceContainer;

class CheckCopilotAccess extends ActionFilter\Base
{
	/**
	 * Checks if user has access to copilot features.
	 * @param Event $event Event.
	 * @return EventResult|null
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		$userId = User::getCurrentUserId();
		$collaberService = ServiceContainer::getInstance()->getCollaberService();
		if(!Util::isIntranetUser($userId) && !$collaberService->isCollaberById($userId))
		{
			$this->errorCollection[] = new Error(
				'This action is available only to intranet users and collabers',
			);

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}