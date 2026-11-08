<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * @internal
 */
final class FormWriteRestrictedException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Writing CRM forms is restricted on the current portal plan.');
	}
}
