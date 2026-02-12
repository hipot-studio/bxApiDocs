<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event\SharingLink;

use Bitrix\Im\V2\Pull\EventType;

class SharingLinkUpdate extends BaseSharingLinkEvent
{
	protected function getBasePullParamsInternal(): array
	{
		return [
			'sharingLink' => $this->sharingLink->toPullFormat(extendedFormat: true),
		];
	}

	protected function getType(): EventType
	{
		return EventType::SharingLinkUpdate;
	}
}
