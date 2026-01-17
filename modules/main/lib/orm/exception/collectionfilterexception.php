<?php

declare(strict_types=1);

namespace Bitrix\Main\ORM\exception;

use Bitrix\Main\SystemException;
use Throwable;

class CollectionFilterException extends SystemException
{
	public function __construct(
		$message = 'Cannot filter collection because it contains unsaved changes.',
		$code = 0,
		$file = "",
		$line = 0,
		Throwable $previous = null
	)
	{
		parent::__construct($message, $code, $file, $line, $previous);
	}
}
