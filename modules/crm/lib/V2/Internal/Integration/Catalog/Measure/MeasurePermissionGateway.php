<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Catalog\Measure;

use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Main\Loader;

/**
 * @internal
 */
class MeasurePermissionGateway
{
	public function canEdit(): bool
	{
		Loader::requireModule('catalog');

		return AccessController::getCurrent()->check(ActionDictionary::ACTION_MEASURE_EDIT);
	}
}
