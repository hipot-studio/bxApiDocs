<?php

namespace Bitrix\BIConnector\Superset\Demo;

enum DemoState: string
{
	case Ready = 'READY';
	case NotReady = 'NOT_READY';
	case RateLimited = 'RATE_LIMITED';
	case Unavailable = 'UNAVAILABLE';
}
