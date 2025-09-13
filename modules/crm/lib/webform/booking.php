<?php

namespace Bitrix\Crm\WebForm;

use Bitrix\Main\Event;

class Booking
{
	public const RESOURCE_FIELD_TYPE = 'booking';

	public static function isResourceForm(array $resultFields): bool
	{
		foreach ($resultFields as $resultField)
		{
			if ($resultField['type'] === self::RESOURCE_FIELD_TYPE)
			{
				return true;
			}
		}

		return false;
	}

	public static function getResourceFieldValue(array $resultFields): ?array
	{
		foreach ($resultFields as $resultField)
		{
			if (
				$resultField['type'] === self::RESOURCE_FIELD_TYPE
				&& is_array($resultField['values'][0])
				&& !empty($resultField['values'][0])
			)
			{
				return $resultField['values'][0];
			}
		}

		return null;
	}

	public static function sendEvent(array $eventParameters): void
	{
		(new Event(
			moduleId: 'crm',
			type: 'OnCrmBookingFormSubmitted',
			parameters: $eventParameters,
		))->send();
	}
}
