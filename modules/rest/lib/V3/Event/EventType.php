<?php

namespace Bitrix\Rest\V3\Event;

enum EventType: string
{
	case Dto = 'dto';
	case Array = 'array';
}