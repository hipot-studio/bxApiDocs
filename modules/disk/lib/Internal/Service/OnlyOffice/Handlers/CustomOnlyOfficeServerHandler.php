<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Handlers;

use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Main\Localization\Loc;

class CustomOnlyOfficeServerHandler extends OnlyOfficeHandler
{
	protected static ?CustomServerInterface $customServer = null;

	public static function getCode()
	{
		return 'customOnlyOfficeServer';
	}

	public static function getName()
	{
		return static::$customServer?->getName() ?? Loc::getMessage('DISK_CUSTOM_ONLYOFFICE_SERVER_HANDLER_NAME');
	}

	public static function setCustomServer(CustomServerInterface $customServer): void
	{
		static::$customServer = $customServer;
	}
}
