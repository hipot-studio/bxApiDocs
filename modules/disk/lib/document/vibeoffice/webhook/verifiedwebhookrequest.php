<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformSource;
use Bitrix\Main\HttpRequest;
use WeakMap;

final class VerifiedWebhookRequest
{
	private static ?WeakMap $requests = null;

	/** @param list<PlatformSource> $sources */
	public function __construct(
		public readonly int $version,
		public readonly string $timestamp,
		public readonly string $event,
		public readonly ?int $deliveryId,
		public readonly ?string $ack,
		public readonly string $rawBody,
		public readonly array $sources = [],
	)
	{
	}

	public static function publish(HttpRequest $request, self $verified): void
	{
		self::$requests ??= new WeakMap();
		self::$requests[$request] = $verified;
	}

	public static function get(HttpRequest $request): ?self
	{
		return self::$requests[$request] ?? null;
	}

	public static function forget(HttpRequest $request): void
	{
		if (self::$requests !== null)
		{
			unset(self::$requests[$request]);
		}
	}
}
