<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageType;

use Bitrix\Bizproc\Internal\Exception\Exception;

class DeleteStorageTypeException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Failed deleting storage type' : $message;
		$code = self::CODE_STORAGE_TYPE_REMOVE;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
