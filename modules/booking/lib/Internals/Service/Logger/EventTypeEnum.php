<?php

namespace Bitrix\Booking\Internals\Service\Logger;

enum EventTypeEnum: string
{
	case DelayedTask = 'delayed_task';
	case Common = 'common';
}
