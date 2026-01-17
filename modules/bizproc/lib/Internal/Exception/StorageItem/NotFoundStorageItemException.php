<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageItem;

use Bitrix\Bizproc\Internal\Exception\Exception;

class NotFoundStorageItemException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Storage item not found' : $message;
		$code = self::CODE_STORAGE_ITEM_NOT_FOUND;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
