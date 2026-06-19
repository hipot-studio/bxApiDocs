<?php

namespace Bitrix\StaffTrack\Internal\Integration\Pull;

class Tag
{
	public static function getUserTag(int $userId): string
	{
		return "stafftrack-user-$userId";
	}
}