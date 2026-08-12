<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

/**
 * Module-level pre-filter for Follow-up read endpoints.
 * Per-callId authorization (admin vs participant, anti-enumeration) is enforced
 * inside FollowUpReader, since it requires the parsed Request DTO and DB lookup.
 */
class CallFollowUpAccess extends Base
{
	public function onBeforeAction(Event $event): EventResult
	{
		$userId = (int)(CurrentUser::get()->getId() ?? 0);
		if ($userId <= 0)
		{
			throw new AccessDeniedException();
		}

		return new EventResult(EventResult::SUCCESS, null, null, $this);
	}
}
