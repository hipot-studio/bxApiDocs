<?php

namespace Bitrix\Crm\Activity\Provider;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\TimelineActivitySync;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Sync extends Base
{
	public const PROVIDER_ID = 'CRM_SYNC';
	public const TYPE_SYNC = 'SYNC';

	public static function getId(): string
	{
		return self::PROVIDER_ID;
	}

	public static function getName(): string
	{
		return (string)Loc::getMessage('CRM_ACTIVITY_PROVIDER_SYNC_NAME');
	}

	public static function getTypes(): array
	{
		$typeName = (string)Loc::getMessage('CRM_ACTIVITY_PROVIDER_SYNC_TYPE_NAME');

		return [
			[
				'NAME' => $typeName,
				'PROVIDER_ID' => self::getId(),
				'PROVIDER_TYPE_ID' => self::TYPE_SYNC,
				'DIRECTIONS' => [
					\CCrmActivityDirection::Incoming => $typeName,
				],
			],
		];
	}

	public static function isAvailable(): bool
	{
		return Feature::enabled(TimelineActivitySync::class);
	}
}
