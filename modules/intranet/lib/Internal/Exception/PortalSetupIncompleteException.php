<?php

namespace Bitrix\Intranet\Internal\Exception;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

class PortalSetupIncompleteException extends SystemException
{
	public function __construct($message = '', $code = 0, $file = '', $line = 0, ?\Throwable $previous = null)
	{
		$message = !empty($message)
			? $message
			: Loc::getMessage('INTRANET_PORTAL_SETUP_INCOMPLETE_EXCEPTION_MESSAGE') ?? 'portal setup incomplete';
		parent::__construct($message, $code, $file, $line, $previous);
	}
}
