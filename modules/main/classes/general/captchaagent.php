<?php

use Bitrix\Main\Type\Callback;

class CCaptchaAgent
{
	public static function DeleteOldCaptcha($sec = 3600)
	{
		global $DB;

		$sec = intval($sec);

		$time = $DB->CharToDateFunction(GetTime(time()-$sec,"FULL"));
		$DB->Query("DELETE FROM b_captcha WHERE DATE_CREATE <= ".$time);

		return new Callback([self::class, 'DeleteOldCaptcha'], [$sec]);
	}
}
