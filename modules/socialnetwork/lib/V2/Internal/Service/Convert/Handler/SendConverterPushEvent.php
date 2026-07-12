<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Internals\EventService;
use Bitrix\Socialnetwork\Item\Workgroup;

class SendConverterPushEvent implements HandlerInterface
{
	public function __invoke(Workgroup $groupBefore, Workgroup $groupAfter): void
	{
		EventService\Service::addEvent(
			EventService\EventDictionary::EVENT_WORKGROUP_CONVERT,
			[
				'GROUP_ID' => $groupAfter->getId(),
			]
		);
	}
}
