<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Mail;

final class IncomingMessageNotificationPolicy
{
	public const SUPPRESS_FIELD = '__mail_suppress_builtin_notifications';
	public const ORIGIN_FIELD = '__mail_delivery_origin';

	public static function shouldNotify(
		array $messageFields,
		int $responsibleId,
		bool $isIncoming,
		string $completed,
	): bool
	{
		return empty($messageFields[self::SUPPRESS_FIELD])
			&& (empty($messageFields['IS_RECOVERED']) || array_key_exists(self::ORIGIN_FIELD, $messageFields))
			&& $responsibleId > 0
			&& $isIncoming
			&& $completed !== 'Y';
	}
}
