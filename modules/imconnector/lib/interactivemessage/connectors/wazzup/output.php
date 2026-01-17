<?php

namespace Bitrix\ImConnector\InteractiveMessage\Connectors\Wazzup;

use \Bitrix\ImConnector\InteractiveMessage;

/**
 * Class Base
 * @package Bitrix\ImConnector\InteractiveMessage\Network
 */
class Output extends InteractiveMessage\Output
{
	public static function processSendingMessage(array $messageFields, string $connectorId): array
	{
		return $messageFields;
	}
}