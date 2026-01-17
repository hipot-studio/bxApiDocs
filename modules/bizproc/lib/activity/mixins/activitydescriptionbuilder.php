<?php

namespace Bitrix\Bizproc\Activity\Mixins;

use Bitrix\Bizproc\RestActivityTable;
use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Main\Localization\Loc;

trait ActivityDescriptionBuilder
{
	private function buildActivityDescription(array $activity): ActivityDescription
	{
		return ActivityDescription::makeFromArray($activity);
	}

	private function buildRestActivityDescription(array $activity, ?string $lang = null): ActivityDescription
	{
		if (empty($lang))
		{
			$lang = LANGUAGE_ID;
		}

		$code = \CBPRuntime::REST_ACTIVITY_PREFIX . $activity['INTERNAL_CODE'];
		$appName = RestActivityTable::getLocalization($activity['APP_NAME'], $lang);
		$description = [
			'NAME' => '[' . $appName . '] ' . RestActivityTable::getLocalization($activity['NAME'], $lang),
			'DESCRIPTION' => RestActivityTable::getLocalization($activity['DESCRIPTION'], $lang),
			'TYPE' => [ActivityType::ACTIVITY->value],
			'CLASS' => $code,
			'JSCLASS' => ActivityDescription::DEFAULT_ACTIVITY_JS_CLASS,
			'CATEGORY' => ['ID' => 'rest'],
			'RETURN' => $this->buildRestReturnProperties($activity, $lang),
		];

		if (isset($activity['FILTER']) && is_array($activity['FILTER']))
		{
			$description['FILTER'] = $activity['FILTER'];
		}

		return ActivityDescription::makeFromArray($description);
	}

	private function buildRestRobotDescription(array $activity, ?string $lang = null): ActivityDescription
	{
		if (empty($lang))
		{
			$lang = LANGUAGE_ID;
		}

		$code = \CBPRuntime::REST_ACTIVITY_PREFIX . $activity['INTERNAL_CODE'];
		$appName = RestActivityTable::getLocalization($activity['APP_NAME'], $lang);
		$description = [
			'NAME' => '[' . $appName . '] ' . RestActivityTable::getLocalization($activity['NAME'], $lang),
			'DESCRIPTION' => RestActivityTable::getLocalization($activity['DESCRIPTION'], $lang),
			'TYPE' => [ActivityType::ACTIVITY->value, ActivityType::ROBOT->value],
			'CLASS' => $code,
			'JSCLASS' => ActivityDescription::DEFAULT_ACTIVITY_JS_CLASS,
			'CATEGORY' => ['ID' => 'rest'],
			'RETURN' => $this->buildRestReturnProperties($activity, $lang),
			'ROBOT_SETTINGS' => ['CATEGORY' => 'other'],
		];

		if (isset($activity['FILTER']) && is_array($activity['FILTER']))
		{
			$description['FILTER'] = $activity['FILTER'];
		}

		return ActivityDescription::makeFromArray($description);
	}

	private function buildRestReturnProperties(array $activity, ?string $lang = null): array
	{
		$properties = [];

		if (!empty($activity['RETURN_PROPERTIES']))
		{
			foreach ($activity['RETURN_PROPERTIES'] as $name => $property)
			{
				$properties[$name] = [
					'NAME' => RestActivityTable::getLocalization($property['NAME'], $lang),
					'TYPE' => $property['TYPE'] ?? \Bitrix\Bizproc\FieldType::STRING,
					'OPTIONS' => $property['OPTIONS'] ?? null,
				];
			}
		}

		if ($activity['USE_SUBSCRIPTION'] !== 'N')
		{
			$properties['IsTimeout'] = [
				'NAME' => Loc::getMessage('BP_LIB_RUNTIME_MIXINS_ACTIVITY_DESCRIPTION_BUILDER_IS_TIMEOUT'),
				'TYPE' => \Bitrix\Bizproc\FieldType::INT,
			];
		}

		return $properties;
	}
}
