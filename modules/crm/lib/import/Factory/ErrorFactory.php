<?php

namespace Bitrix\Crm\Import\Factory;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

final class ErrorFactory
{
	public function getImportFileNotFoundError(): Error
	{
		return new Error(
			message: Loc::getMessage('CRM_IMPORT_ERROR_IMPORT_FILE_NOT_FOUND'),
			code: 'IMPORT_FILE_NOT_FOUND',
		);
	}

	public function getImportFileNotSupportedError(): Error
	{
		return new Error(
			message: Loc::getMessage('CRM_IMPORT_ERROR_IMPORT_FILE_NOT_SUPPORTED'),
			code: 'IMPORT_FILE_NOT_SUPPORTED',
		);
	}

	public function getCurrentLineNotFoundError(): Error
	{
		return new Error(
			message: Loc::getMessage('CRM_IMPORT_ERROR_CURRENT_LINE_NOT_FOUND'),
			code: 'CURRENT_LINE_NOT_FOUND',
		);
	}
}
