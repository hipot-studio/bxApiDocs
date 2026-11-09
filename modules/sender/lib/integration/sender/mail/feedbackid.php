<?php

namespace Bitrix\Sender\Integration\Sender\Mail;

use Bitrix\Main\Config\Option;

/** Owns the Feedback-ID header format and validates its portal settings. */
final class FeedbackId
{
	public const HEADER_NAME = 'Feedback-ID';
	public const OPTION_SOURCE_ID = 'mail_feedback_id_source';
	public const OPTION_SENDER_ID = 'mail_feedback_id_sender';
	public const OPTION_ENABLED = 'mail_feedback_id_enabled';
	public const OPTION_PORTAL_ID = 'mail_feedback_id_portal_id';

	private const MAIL_TYPE = 'promo';

	// The /D modifier is load-bearing: without it `$` also matches before a trailing newline, and a
	// setting ending with one is exactly what injects extra headers into the letter.
	private const SOURCE_ID_PATTERN = '/^[A-Za-z0-9_-]+$/D';
	private const SENDER_ID_PATTERN = '/^[A-Za-z0-9_-]{5,15}$/D';
	private const PORTAL_ID_PATTERN = '/^[a-fA-F0-9]{32}$/D';

	public static function isValidSourceId(string $value): bool
	{
		return preg_match(self::SOURCE_ID_PATTERN, $value) === 1;
	}

	public static function isValidSenderId(string $value): bool
	{
		return preg_match(self::SENDER_ID_PATTERN, $value) === 1;
	}

	public static function build(int $letterId): ?string
	{
		if ($letterId <= 0 || Option::get('sender', self::OPTION_ENABLED, 'N') !== 'Y')
		{
			return null;
		}

		$portalId = (string)Option::get('sender', self::OPTION_PORTAL_ID, '');
		$sourceId = (string)Option::get('sender', self::OPTION_SOURCE_ID, '');
		$senderId = (string)Option::get('sender', self::OPTION_SENDER_ID, '');
		if (
			preg_match(self::PORTAL_ID_PATTERN, $portalId) !== 1
			|| !self::isValidSourceId($sourceId)
			|| !self::isValidSenderId($senderId)
		)
		{
			return null;
		}

		return 'ltr_' . $portalId . '_' . $letterId . ':' . $sourceId . ':' . self::MAIL_TYPE . ':' . $senderId;
	}

	public static function applyTo(array $headers, int $letterId): array
	{
		$value = self::build($letterId);
		if ($value === null)
		{
			return $headers;
		}

		// the administrator may have put the same name into the permanent headers setting
		foreach (array_keys($headers) as $name)
		{
			if (strcasecmp((string)$name, self::HEADER_NAME) === 0)
			{
				unset($headers[$name]);
			}
		}

		$headers[self::HEADER_NAME] = $value;

		return $headers;
	}
}
