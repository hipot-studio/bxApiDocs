<?php

namespace Bitrix\Crm\Activity\Planner;

use Bitrix\Crm\Activity\Provider\ToDo\ToDo;

final class TypeIconResolver
{
	public static function resolve(array $activity): string
	{
		$typeId = (int)($activity['TYPE_ID'] ?? \CCrmActivityType::Undefined);
		$direction = (int)($activity['DIRECTION'] ?? \CCrmActivityDirection::Undefined);
		$providerId = (string)($activity['PROVIDER_ID'] ?? '');

		if ($typeId === \CCrmActivityType::Call)
		{
			return $direction === \CCrmActivityDirection::Outgoing ? 'call-outgoing' : 'call';
		}

		if ($typeId === \CCrmActivityType::Meeting)
		{
			return 'meet';
		}

		if ($typeId === \CCrmActivityType::Email)
		{
			return $direction === \CCrmActivityDirection::Outgoing ? 'mail' : 'mail-send';
		}

		if (
			$typeId === \CCrmActivityType::Provider
			&& $providerId === ToDo::PROVIDER_ID
		)
		{
			return 'todo';
		}

		if ($providerId === 'CRM_EXTERNAL_CHANNEL')
		{
			if (
				isset($activity['ORIGINATOR_ID'])
				&& in_array($activity['ORIGINATOR_ID'], ['BITRIX', 'WORDPRESS', 'DRUPAL', 'JOOMLA', 'MAGENTO'], true)
			)
			{
				return 'cmsplugins';
			}

			return 'onec';
		}

		if ($providerId === 'CRM_LF_MESSAGE')
		{
			return 'live-feed';
		}

		if ($providerId === 'CRM_WEBFORM')
		{
			return 'form';
		}

		if ($providerId === 'IMOPENLINES_SESSION')
		{
			return 'chat';
		}

		if ($providerId === 'VISIT_TRACKER')
		{
			return 'visit-tracker';
		}

		if ($providerId === 'CRM_REQUEST')
		{
			return 'deal-request';
		}

		if ($providerId === 'CALL_LIST')
		{
			return 'call-list';
		}

		if ($providerId === 'CRM_SMS')
		{
			return 'crm-sms';
		}

		if ($providerId === 'REST_APP')
		{
			return 'rest_app';
		}

		return '';
	}
}
