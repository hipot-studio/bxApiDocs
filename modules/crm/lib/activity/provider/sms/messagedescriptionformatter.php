<?php

namespace Bitrix\Crm\Activity\Provider\Sms;

final class MessageDescriptionFormatter
{
	public static function prepareHtml(string $description): string
	{
		$description = html_entity_decode($description, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
		$description = html_entity_decode($description, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');

		return preg_replace(
			'/\r\n|\r|\n/',
			'<br/>',
			htmlspecialcharsbx($description)
		);
	}
}
