<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageType;

use Bitrix\Bizproc\Internal\Exception\Exception;

class UpdateStorageTypeException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Failed updating storage type' : $message;
		$code = self::CODE_STORAGE_TYPE_UPDATE;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
