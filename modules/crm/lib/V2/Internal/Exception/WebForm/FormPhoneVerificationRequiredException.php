<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * @internal
 */
final class FormPhoneVerificationRequiredException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Phone verification is required to write CRM forms.');
	}
}
