<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Settings;

use LogicException;

/**
 * @internal
 */
final class SettingNotFoundException extends LogicException
{
	public function __construct(string $identifier)
	{
		parent::__construct("CRM setting '{$identifier}' is not registered.");
	}
}
