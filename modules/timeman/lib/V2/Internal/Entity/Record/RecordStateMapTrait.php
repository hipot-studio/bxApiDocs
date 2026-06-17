<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Record;

trait RecordStateMapTrait
{
	private static function mapState(array $props): RecordState
	{
		if (($props['state'] ?? null) instanceof RecordState)
		{
			return $props['state'];
		}

		if (is_array($props['state'] ?? null))
		{
			return RecordState::mapFromArray($props['state']);
		}

		$statusStr = static::mapString($props, 'status', RecordStatus::Unknown->value) ?? RecordStatus::Unknown->value;

		return RecordState::fromStatusOnly(RecordStatus::tryFrom($statusStr) ?? RecordStatus::Unknown);
	}
}
