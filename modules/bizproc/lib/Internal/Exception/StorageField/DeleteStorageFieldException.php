<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageField;

use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\Localization\Loc;

class DeleteStorageFieldException extends Exception
{
	public function __construct($message = '')
	{
		if (empty($message))
		{
			$message = Loc::getMessage('BIZPROC_EXCEPTION_STORAGE_FIELD_DELETING') ?? '';
		}

		$code = self::CODE_STORAGE_FIELD_REMOVE;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
