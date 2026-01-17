<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageItem;

use Bitrix\Bizproc\Internal\Exception\Exception;

class UpdateStorageItemException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Failed updating storage item' : $message;
		$code = self::CODE_STORAGE_ITEM_UPDATE;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
