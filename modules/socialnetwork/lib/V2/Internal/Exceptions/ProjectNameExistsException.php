<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Exceptions;

use Bitrix\Main\Error;
use RuntimeException;

class ProjectNameExistsException extends RuntimeException
{
	public const ERROR_CODE = 'ERROR_GROUP_NAME_EXISTS';

	public function __construct(string $message = 'Group name already exists')
	{
		parent::__construct($message);
	}

	public function toError(): Error
	{
		return new Error($this->getMessage(), self::ERROR_CODE);
	}
}
