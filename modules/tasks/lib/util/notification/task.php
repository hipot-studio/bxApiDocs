<?php

namespace Bitrix\Tasks\Util\Notification;

use Bitrix\Tasks\Util\Type\DateTime;

/**
 * Class Task
 *
 * @package Bitrix\Tasks\Util\Notification
 */
final class Task
{
	public static function createOverdueChats(): void
	{
		return;
	}

	/**
	 * Returns start time of current day (00:00:00)
	 *
	 * @return DateTime
	 * @throws \Bitrix\Main\ObjectException
	 */
	private static function getDayStartDateTime(): DateTime
	{
		$now = new DateTime();
		$structure = $now->getTimeStruct();
		$now->add('-T'.($structure['SECOND'] + 60 * $structure['MINUTE'] + 3600 * $structure['HOUR']).'S');

		return $now;
	}
}
