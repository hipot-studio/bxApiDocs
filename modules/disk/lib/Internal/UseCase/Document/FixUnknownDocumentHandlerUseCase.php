<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\UseCase\Document;

use Bitrix\Disk\Document\BitrixHandler;
use Bitrix\Disk\Public\Command\ChangeDefaultViewerServiceCommand;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Application;
use CAdminNotify;
use Throwable;

Loc::loadMessages(__FILE__);

class FixUnknownDocumentHandlerUseCase
{
	private ExceptionHandler $exceptionHandler;

	public function __construct()
	{
		$this->exceptionHandler = Application::getInstance()->getExceptionHandler();
	}

	public function fix(): void
	{
		try
		{
			(new ChangeDefaultViewerServiceCommand(BitrixHandler::getCode()))->run();

			$this->notifyPortalAdmin();
		}
		catch (Throwable $e)
		{
			$this->exceptionHandler->writeToLog($e);
		}

	}

	private function notifyPortalAdmin(): void
	{
		CAdminNotify::add([
			'MESSAGE' => Loc::getMessage('DISK_FIX_UNKNOWN_DOCUMENT_HANDLER_NOTIFICATION', ['#LANGUAGE_ID#' => LANGUAGE_ID]),
			'MODULE_ID' => 'disk',
			'ENABLE_CLOSE' => 'Y',
			'TAG' => 'disk_invalid_document_handler_fix',
		]);
	}
}
