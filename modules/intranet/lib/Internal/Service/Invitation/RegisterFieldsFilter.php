<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Invitation;

final class RegisterFieldsFilter
{
	public const ALLOWED_FIELDS = [
		'EMAIL',
		'PHONE',
		'PHONE_COUNTRY',
		'DEPARTMENT_ID',
		'MESSAGE_TEXT',
		'CONTEXT',
	];

	public const ALLOWED_RECIPIENT_FIELDS = [
		'EMAIL' => [
			'EMAIL',
			'NAME',
			'LAST_NAME',
		],
		'PHONE' => [
			'PHONE',
			'PHONE_COUNTRY',
			'NAME',
			'LAST_NAME',
		],
	];

	public static function filter(array $fields): array
	{
		$fields = array_intersect_key($fields, array_flip(self::ALLOWED_FIELDS));

		foreach (self::ALLOWED_RECIPIENT_FIELDS as $field => $allowedRecipientFields)
		{
			if (isset($fields[$field]) && is_array($fields[$field]))
			{
				$fields[$field] = self::filterRecipients($fields[$field], $allowedRecipientFields);
			}
		}

		return $fields;
	}

	private static function filterRecipients(array $recipients, array $allowedRecipientFields): array
	{
		$filtered = [];

		foreach ($recipients as $key => $recipient)
		{
			$filtered[$key] = is_array($recipient)
				? array_intersect_key($recipient, array_flip($allowedRecipientFields))
				: $recipient;
		}

		return $filtered;
	}
}
