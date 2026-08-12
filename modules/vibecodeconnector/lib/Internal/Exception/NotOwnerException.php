<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

use Bitrix\Main\AccessDeniedException;

final class NotOwnerException extends AccessDeniedException
{
	public function __construct(
		string $message = 'Only the owner can perform this operation',
		?\Throwable $previous = null,
	)
	{
		parent::__construct($message, $previous);
	}
}
