<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageType;

use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\Localization\Loc;

class CreateStorageTypeException extends Exception
{
	public function __construct($message = '')
	{
		if (empty($message))
		{
			$message = Loc::getMessage('BIZPROC_EXCEPTION_STORAGE_TYPE_CREATING') ?? '';
		}

		$code = self::CODE_STORAGE_TYPE_CREATE;

		if (str_contains($message, 'Duplicate entry') || str_contains($message, 'duplicate key'))
		{
			$message = Loc::getMessage('BIZPROC_EXCEPTION_STORAGE_TYPE_DUPLICATE_ENTRY') ?? '';
			$code = self::CODE_STORAGE_TYPE_DUPLICATE_ENTRY;
		}

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
