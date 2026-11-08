<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

final class DeliveryContext
{
	public const ORIGIN_FIELD = '__mail_delivery_origin';
	public const SUPPRESS_BUILTIN_NOTIFICATIONS_FIELD = '__mail_suppress_builtin_notifications';

	private function __construct(private readonly string $origin)
	{
	}

	public static function live(): self
	{
		return new self(DeliveryOrigin::LIVE);
	}

	public static function backfill(): self
	{
		return new self(DeliveryOrigin::BACKFILL);
	}

	public static function repair(): self
	{
		return new self(DeliveryOrigin::REPAIR);
	}

	public static function migration(): self
	{
		return new self(DeliveryOrigin::MIGRATION);
	}

	public static function unknown(): self
	{
		return new self(DeliveryOrigin::UNKNOWN);
	}

	public static function withoutBoundary(bool $mailboxEnabled, bool $isRecovered): self
	{
		return $mailboxEnabled || $isRecovered ? self::unknown() : self::live();
	}

	public static function fromOrigin(string $origin): self
	{
		return DeliveryOrigin::isValid($origin) ? new self($origin) : self::unknown();
	}

	public function getOrigin(): string
	{
		return $this->origin;
	}

	public function shouldNotify(): bool
	{
		return $this->origin === DeliveryOrigin::LIVE;
	}

	public function forKnownMessage(bool $isKnown): self
	{
		return $isKnown ? self::repair() : $this;
	}

	public function toFilterFields(): array
	{
		return [
			self::ORIGIN_FIELD => $this->origin,
			self::SUPPRESS_BUILTIN_NOTIFICATIONS_FIELD => !$this->shouldNotify(),
		];
	}
}
