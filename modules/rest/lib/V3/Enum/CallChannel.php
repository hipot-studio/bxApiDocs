<?php

namespace Bitrix\Rest\V3\Enum;

enum CallChannel: string
{
	case RestTransport = 'rest_transport';
	case Mcp = 'mcp';
}
