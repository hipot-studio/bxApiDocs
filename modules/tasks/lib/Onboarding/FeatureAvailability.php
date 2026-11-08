<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding;

use Bitrix\Main\Config\Option;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;

/**
 * The moment the onboarding feature became available on this portal.
 *
 * The option is written once: by the updater on existing portals and by InstallDB
 * on new ones. Reading never writes it back, because the value is read on every
 * task creation and a lazy initialization there would silently move the segment
 * boundary to whenever the first task happened to be created.
 */
final class FeatureAvailability
{
	public const OPTION_NAME = 'tasks_onboarding_feature_start_date';

	private const DATE_FORMAT = 'Y-m-d H:i:s';

	public static function getStartDate(): ?DateTime
	{
		$startDate = (string)Option::get('tasks', self::OPTION_NAME, '');
		if ($startDate === '')
		{
			return null;
		}

		try
		{
			return new DateTime($startDate, self::DATE_FORMAT);
		}
		catch (ObjectException)
		{
			// A malformed value is treated as a missing one: the callers run in the task
			// creation path and must not be broken by a hand-edited option.
			return null;
		}
	}
}
