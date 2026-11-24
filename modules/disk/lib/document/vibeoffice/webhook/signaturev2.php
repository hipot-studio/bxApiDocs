<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

final class SignatureV2
{
	public const CONTRACT_ID = 'vo-webhook-v2-ns1';
	public const ACK = 'saved-apply-v1';
	private const EVENTS = ['document.saved', 'document.session-closed', 'document.failover', 'host.probe'];

	public static function sign(
		string $secret,
		string $timestamp,
		string $event,
		string $delivery,
		?string $ack,
		string $body,
	): string
	{
		return 'v2=' . hash_hmac('sha256', self::canonical($timestamp, $event, $delivery, $ack, $body), $secret);
	}

	public static function canonical(
		string $timestamp,
		string $event,
		string $delivery,
		?string $ack,
		string $body,
	): string
	{
		return "VO-WEBHOOK\0v2\0"
			. self::netstring($timestamp)
			. self::netstring($event)
			. self::netstring($delivery)
			. ($ack === null ? '0' : '1' . self::netstring($ack))
			. self::netstring($body);
	}

	public static function validMetadata(string $timestamp, string $event, string $delivery, ?string $ack): bool
	{
		return preg_match('/\A[1-9][0-9]{0,9}\z/D', $timestamp) === 1
			&& self::withinLimit($timestamp, (string)PHP_INT_MAX)
			&& in_array($event, self::EVENTS, true)
			&& preg_match('/\A[1-9][0-9]{0,18}\z/D', $delivery) === 1
			&& self::withinLimit($delivery, '9223372036854775807')
			&& self::withinLimit($delivery, (string)PHP_INT_MAX)
			&& ($ack === null || $ack === self::ACK);
	}

	private static function withinLimit(string $value, string $maximum): bool
	{
		return strlen($value) < strlen($maximum)
			|| (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0);
	}

	private static function netstring(string $value): string
	{
		return strlen($value) . ':' . $value . ',';
	}
}
