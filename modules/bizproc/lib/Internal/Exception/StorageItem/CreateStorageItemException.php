<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageItem;

use Bitrix\Bizproc\Internal\Exception\Exception;

class CreateStorageItemException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Failed creating storage item' : $message;
		$code = self::CODE_STORAGE_ITEM_CREATE;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
