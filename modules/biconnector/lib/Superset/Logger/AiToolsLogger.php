<?php

namespace Bitrix\BIConnector\Superset\Logger;

class AiToolsLogger extends Logger
{
	final protected static function getAuditSubType(): string
	{
		return 'AI_TOOLS';
	}
}
