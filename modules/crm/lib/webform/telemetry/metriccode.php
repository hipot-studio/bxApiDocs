<?php

declare(strict_types=1);

namespace Bitrix\Crm\WebForm\Telemetry;

final class MetricCode
{
	public const DURATION_SUM = 'duration_sum';
	public const DURATION_COUNT = 'duration_count';
	public const FULLY_FILLED = 'fully_filled';
	public const PUBLIC_ATTEMPT_QUOTA = 'public_attempt_quota';

	public const SOURCE_SITE = 'source_site';
	public const SOURCE_LANDING = 'source_landing';
	public const SOURCE_QR = 'source_qr';
	public const SOURCE_EMAIL = 'source_email';
	public const SOURCE_MESSENGER = 'source_messenger';
	public const SOURCE_DIRECT_LINK = 'source_direct_link';
	public const SOURCE_WIDGET = 'source_widget';
	public const SOURCE_UNKNOWN = 'source_unknown';

	public const DEVICE_DESKTOP = 'device_desktop';
	public const DEVICE_MOBILE = 'device_mobile';
	public const DEVICE_OTHER = 'device_other';

	public const ERROR_VALIDATION = 'error_validation';
	public const ERROR_OTHER = 'error_other';

	public const TYPE_EVENT = 'type_event';
	public const TYPE_STATISTICS = 'type_statistics';
	public const TYPE_FEEDBACK = 'type_feedback';
	public const TYPE_OTHER = 'type_other';

	private const SOURCE_CODES = [
		SubmissionClassifier::SOURCE_SITE => self::SOURCE_SITE,
		SubmissionClassifier::SOURCE_LANDING => self::SOURCE_LANDING,
		SubmissionClassifier::SOURCE_QR => self::SOURCE_QR,
		SubmissionClassifier::SOURCE_EMAIL => self::SOURCE_EMAIL,
		SubmissionClassifier::SOURCE_MESSENGER => self::SOURCE_MESSENGER,
		SubmissionClassifier::SOURCE_DIRECT_LINK => self::SOURCE_DIRECT_LINK,
		SubmissionClassifier::SOURCE_WIDGET => self::SOURCE_WIDGET,
		SubmissionClassifier::SOURCE_UNKNOWN => self::SOURCE_UNKNOWN,
	];

	private const DEVICE_CODES = [
		SubmissionClassifier::DEVICE_DESKTOP => self::DEVICE_DESKTOP,
		SubmissionClassifier::DEVICE_MOBILE => self::DEVICE_MOBILE,
		SubmissionClassifier::DEVICE_OTHER => self::DEVICE_OTHER,
	];

	private const TYPE_CODES = [
		SubmissionClassifier::TYPE_EVENT => self::TYPE_EVENT,
		SubmissionClassifier::TYPE_STATISTICS => self::TYPE_STATISTICS,
		SubmissionClassifier::TYPE_FEEDBACK => self::TYPE_FEEDBACK,
		SubmissionClassifier::TYPE_OTHER => self::TYPE_OTHER,
	];

	public static function forSource(string $source): string
	{
		return self::SOURCE_CODES[$source] ?? self::SOURCE_UNKNOWN;
	}

	public static function forDevice(string $device): string
	{
		return self::DEVICE_CODES[$device] ?? self::DEVICE_OTHER;
	}

	public static function forError(string $errorType): ?string
	{
		return match ($errorType)
		{
			'validation' => self::ERROR_VALIDATION,
			'other' => self::ERROR_OTHER,
			default => null,
		};
	}

	public static function forType(string $type): string
	{
		return self::TYPE_CODES[$type] ?? self::TYPE_OTHER;
	}
}
