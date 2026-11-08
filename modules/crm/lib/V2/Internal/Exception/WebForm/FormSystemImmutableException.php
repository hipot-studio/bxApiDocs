<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * @internal
 */
final class FormSystemImmutableException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('System CRM form can not be changed or deleted.');
	}
}
