<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Document;

use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Service\OnlyOffice\Handlers\CustomOnlyOfficeServerHandler;
use Bitrix\Disk\Internal\Service\R7\Handlers\CustomR7ServerHandler;

/**
 * Portal-wide answer which handler or code is the office editor: the OnlyOffice family, including the custom
 * servers of the client, or VibeOffice.
 *
 * The office editor is addressed by the entry code of OnlyOffice whatever engine opens the document; the engine
 * is chosen by {@see \Bitrix\Disk\Document\DocumentHandlersManager::resolveEffectiveHandler()}. Whether the editor
 * is available on the portal is answered by {@see OfficeEditorAvailability}, not here.
 */
final class OfficeEngineRecognizer
{
	public static function isOfficeEngine(?DocumentHandler $handler): bool
	{
		return $handler instanceof OnlyOfficeHandler || $handler instanceof VibeofficeHandler;
	}

	public static function isOfficeEngineCode(string $code): bool
	{
		return in_array($code, self::listOfficeEditorCodes(), true);
	}

	public static function isEntryCode(string $code): bool
	{
		return $code === OnlyOfficeHandler::getCode();
	}

	public static function isClientServerCode(string $code): bool
	{
		return in_array($code, self::listClientServerCodes(), true);
	}

	/**
	 * @return string[]
	 */
	public static function listOfficeEditorCodes(): array
	{
		return array_values(array_unique([
			OnlyOfficeHandler::getCode(),
			VibeofficeHandler::getCode(),
			...self::listClientServerCodes(),
			...Driver::getInstance()->getDocumentHandlersManager()->getRegisteredOfficeEngineCodes(),
		]));
	}

	/**
	 * @return string[]
	 */
	private static function listClientServerCodes(): array
	{
		return [
			CustomR7ServerHandler::getCode(),
			CustomOnlyOfficeServerHandler::getCode(),
		];
	}
}
