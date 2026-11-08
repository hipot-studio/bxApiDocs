<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Record;

final readonly class AutomaticModeAvailability
{
	public const MODE_SCHEDULE = 'schedule';
	public const MODE_TIME_TRACKING = 'time_tracking';
	public const MODE_NONE = 'none';
	public const MODE_UNKNOWN = 'unknown';

	public const REASON_NOT_CONFIGURED = 'not_configured';
	public const REASON_INTEGRATION_UNAVAILABLE = 'integration_unavailable';

	public function __construct(
		public bool $trusted,
		public bool $available,
		public string $mode,
		public ?string $unavailableReason,
	)
	{
	}

	public static function notConfigured(): self
	{
		return new self(
			trusted: true,
			available: false,
			mode: self::MODE_NONE,
			unavailableReason: self::REASON_NOT_CONFIGURED,
		);
	}

	public static function unavailable(): self
	{
		return new self(
			trusted: false,
			available: false,
			mode: self::MODE_UNKNOWN,
			unavailableReason: self::REASON_INTEGRATION_UNAVAILABLE,
		);
	}
}
