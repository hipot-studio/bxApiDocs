<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Recyclebin;

use Bitrix\Main\Loader;

/**
 * The single point CRM V2 asks whether the recycle bin lives on this portal at all.
 *
 * The module is an optional dependency of a portal: its absence is answered with a refusal and not
 * with an error.
 *
 * @internal
 */
class ModuleGateway
{
	public function isModuleAvailable(): bool
	{
		return Loader::includeModule('recyclebin');
	}
}
