<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\R7\Handlers;

use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Main\Localization\Loc;

class CustomR7ServerHandler extends OnlyOfficeHandler
{
	protected static ?CustomServerInterface $customServer = null;

	public static function getCode()
	{
		return 'customR7Server';
	}

	public static function getName()
	{
		return static::$customServer?->getName() ?? Loc::getMessage('DISK_CUSTOM_R7_SERVER_HANDLER_NAME');
	}

	public static function setCustomServer(CustomServerInterface $customServer): void
	{
		static::$customServer = $customServer;
	}
}
