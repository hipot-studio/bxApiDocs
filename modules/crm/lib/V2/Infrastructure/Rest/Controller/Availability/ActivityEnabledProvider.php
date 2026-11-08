<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\Availability;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\RestV3CrudDeal;
use Bitrix\Rest\V3\Schema\CheckEnabledProvider;

/**
 * Whether the activity methods of REST V3 are published. They go out together with the rest of the current
 * wave of CRM methods, so the answer is the state of {@see RestV3CrudDeal}.
 */
final class ActivityEnabledProvider implements CheckEnabledProvider
{
	public function isEnabled(): bool
	{
		return Feature::enabled(RestV3CrudDeal::class);
	}
}
