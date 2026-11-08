<?php

namespace Bitrix\BIConnector\Superset\Logger;

class PresentedErrorLogger extends Logger
{
	final protected static function getAuditSubType(): string
	{
		return 'PRESENTED';
	}
}
