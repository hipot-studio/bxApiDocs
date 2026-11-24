<?php

namespace Bitrix\UI\Form;

use Bitrix\Main;

final class ConfigurationChangeEvent
{
	public const EVENT_NAME = 'onUIFormConfigurationChange';

	public const KIND_LAYOUT = 'layout';
	public const KIND_ASSIGNMENT = 'assignment';
	public const KIND_RESET = 'reset';

	public static function send(
		string $categoryName,
		string $configId,
		string $scope,
		int $userScopeId,
		?array $userIds,
		string $kind,
		?string $eventId = null,
	): void
	{
		if ($userIds !== null)
		{
			$userIds = array_values(array_unique(array_map('intval', $userIds)));
		}

		$event = new Main\Event(
			'ui',
			self::EVENT_NAME,
			[
				'CATEGORY_NAME' => $categoryName,
				'CONFIG_ID' => $configId,
				'SCOPE' => $scope,
				'USER_SCOPE_ID' => $userScopeId,
				'USER_IDS' => $userIds,
				'KIND' => $kind,
				'EVENT_ID' => $eventId,
			]
		);
		$event->send();
	}
}
