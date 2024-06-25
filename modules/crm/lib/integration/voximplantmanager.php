<?php

namespace Bitrix\Crm\Integration;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;

class VoxImplantManager
{
	private const ORIGIN_ID_PREFIX = 'VI_';

	public static function getCallInfo($callID)
	{
		if(!Loader::includeModule('voximplant'))
		{
			return null;
		}

		$info = \CVoxImplantHistory::getBriefDetails($callID);
		return is_array($info) ? $info : null;
	}

	public static function getCallDuration(string $callId): ?int
	{
		$info = self::getCallInfo($callId) ?? [];

		return isset($info['DURATION']) ? (int)$info['DURATION'] : null;
	}

	public static function saveComment($callId, $comment)
	{
		if(!Loader::includeModule('voximplant'))
		{
			return null;
		}

		\CVoxImplantHistory::saveComment($callId, $comment);
	}

	final public static function isActivityBelongsToVoximplant(array $activityFields): bool
	{
		return (
			isset($activityFields['PROVIDER_ID'])
			&& $activityFields['PROVIDER_ID'] === Call::ACTIVITY_PROVIDER_ID
			&& isset($activityFields['ORIGIN_ID'])
			&& is_string($activityFields['ORIGIN_ID'])
			&& self::isVoxImplantOriginId($activityFields['ORIGIN_ID'])
		);
	}

	final public static function isVoxImplantOriginId(string $originId): bool
	{
		return str_starts_with($originId, self::ORIGIN_ID_PREFIX);
	}

	final public static function extractCallIdFromOriginId(string $originId): string
	{
		if (!self::isVoxImplantOriginId($originId))
		{
			throw new ArgumentException('originId should belong to voximplant');
		}

		return str_replace(self::ORIGIN_ID_PREFIX, '', $originId);
	}
}
