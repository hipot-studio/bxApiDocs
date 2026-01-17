<?php

namespace Bitrix\Bizproc\Internal\Exception\StorageItem;

use Bitrix\Bizproc\Internal\Exception\Exception;

class DeleteStorageItemException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Failed deleting storage item' : $message;
		$code = self::CODE_STORAGE_ITEM_REMOVE;

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
