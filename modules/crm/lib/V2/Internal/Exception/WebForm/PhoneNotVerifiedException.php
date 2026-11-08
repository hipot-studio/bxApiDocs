<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * @internal
 */
final class PhoneNotVerifiedException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('The phone number of the CRM form is not verified.');
	}
}
