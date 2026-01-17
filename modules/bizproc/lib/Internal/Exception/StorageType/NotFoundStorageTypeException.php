<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageType;

use Bitrix\Bizproc\Internal\Exception\Exception;

class NotFoundStorageTypeException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Storage type not found' : $message;
		$code = self::CODE_STORAGE_TYPE_NOT_FOUND;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
