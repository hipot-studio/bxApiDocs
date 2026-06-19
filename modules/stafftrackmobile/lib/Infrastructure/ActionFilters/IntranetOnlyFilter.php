<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\ActionFilters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\StaffTrack\Internal\Integration\Extranet\UserService as ExtranetUserService;

final class IntranetOnlyFilter extends Base
{
	public function onBeforeAction(Event $event)
	{
		if (\Bitrix\Main\Loader::includeModule('intranet') && !\Bitrix\Intranet\Util::isIntranetUser())
		{
			$this->addError(new \Bitrix\Main\Error('Access denied for extranet users', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$currentUserId = (int)CurrentUser::get()->getId();

		if ($this->isExternalUser($currentUserId))
		{
			$this->addError(new \Bitrix\Main\Error('Access denied', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}
	}

	private function isExternalUser(int $userId): bool
	{
		return ExtranetUserService::isCollaber($userId) || ExtranetUserService::isExtranet($userId);
	}
}
