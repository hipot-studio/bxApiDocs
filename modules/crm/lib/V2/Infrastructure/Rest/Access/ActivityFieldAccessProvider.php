<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Access;

use Bitrix\Crm\V2\Infrastructure\Rest\Controller\Availability\ActivityEnabledProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Access\FieldAccessContext;
use Bitrix\Rest\V3\Access\FieldAccessProvider;
use Bitrix\Rest\V3\Schema\CheckEnabledProvider;

/**
 * Whether the field methods of the activity contract answer, asked on every request.
 *
 * The field methods are served by the system controller of REST, so the check of the wave the module
 * controller makes for itself never runs for them. The `isEnabled` of their description does refuse the
 * call, but it is computed while the schema is built and then cached for an hour, so a switched off flag
 * would keep the contract going out until that cache expires. What the schema caches of this class is its
 * name alone, and the decision below is taken anew for every call - which is what closes that window.
 */
final class ActivityFieldAccessProvider implements FieldAccessProvider
{
	public const ERROR_NOT_PUBLISHED = 'CRM_ACTIVITY_FIELDS_NOT_PUBLISHED';

	private CheckEnabledProvider $availability;

	/**
	 * @param CheckEnabledProvider|null $availability whether the methods are published, the flag of the wave
	 *        by default. The filter of REST builds the provider with no arguments, so the dependency stays
	 *        optional.
	 */
	public function __construct(?CheckEnabledProvider $availability = null)
	{
		$this->availability = $availability ?? new ActivityEnabledProvider();
	}

	public function decide(FieldAccessContext $context): Result
	{
		if ($this->availability->isEnabled())
		{
			return new Result();
		}

		return (new Result())->addError(new Error(
			"The field methods of '{$context->actionUri}' are not published.",
			self::ERROR_NOT_PUBLISHED,
		));
	}
}
