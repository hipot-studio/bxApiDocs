<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use RuntimeException;

/**
 * @internal
 */
final class OpenLinesNotInstalledException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('The open lines module is not installed on the portal.');
	}
}
