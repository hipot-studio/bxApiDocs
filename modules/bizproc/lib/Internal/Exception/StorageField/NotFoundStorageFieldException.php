<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageField;

use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\Localization\Loc;

class NotFoundStorageFieldException extends Exception
{
	public function __construct($message = '')
	{
		if (empty($message))
		{
			$message = Loc::getMessage('BIZPROC_EXCEPTION_STORAGE_FIELD_NOT_FOUND') ?? '';
		}

		$code = self::CODE_STORAGE_FIELD_NOT_FOUND;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
