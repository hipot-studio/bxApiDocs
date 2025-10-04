<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\DelayedTask\Data;

use Bitrix\Booking\Internals\Service\DelayedTask\DelayedTaskType;

class DelayedTaskDataMapper
{
	private static function getDataClassByType(DelayedTaskType $delayedTaskType) : string
	{
		return match ($delayedTaskType)
		{
			DelayedTaskType::ResourceLinkedEntitiesChanged => ResourceLinkedEntitiesChangedData::class,
		};
	}

	public static function mapFromArray(DelayedTaskType $delayedTaskType, array $params): DataInterface
	{
		/** @var DataInterface $className */
		$className = self::getDataClassByType($delayedTaskType);

		return $className::mapFromArray($params);
	}
}
