<?php

namespace Bitrix\Call;

/**
 * Class VideoStrategyType
 * @see BX.Call.VideoStrategy.Type
 * @internal
 */

class VideoStrategyType
{
	public const ALLOW_ALL = 'AllowAll';
	public const ALLOW_NONE = 'AllowNone';
	public const ONLY_SPEAKER = 'OnlySpeaker';
	public const CURRENTLY_TALKING = 'CurrentlyTalking';

	public static function getList(): array
	{
		return [static::ALLOW_ALL, static::ALLOW_NONE, static::ONLY_SPEAKER, static::CURRENTLY_TALKING];
	}
}