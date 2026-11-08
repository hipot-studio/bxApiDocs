<?php

declare(strict_types=1);

namespace Bitrix\Crm\WebForm\Telemetry;

use Bitrix\Crm\WebForm\Internals\FieldTable;

final class SubmissionClassifier
{
	public const SOURCE_SITE = 'site';
	public const SOURCE_LANDING = 'landing';
	public const SOURCE_QR = 'qr';
	public const SOURCE_EMAIL = 'email';
	public const SOURCE_MESSENGER = 'messenger';
	public const SOURCE_DIRECT_LINK = 'direct_link';
	public const SOURCE_WIDGET = 'widget';
	public const SOURCE_UNKNOWN = 'unknown';

	public const SOURCE_CODES = [
		self::SOURCE_SITE,
		self::SOURCE_LANDING,
		self::SOURCE_QR,
		self::SOURCE_EMAIL,
		self::SOURCE_MESSENGER,
		self::SOURCE_DIRECT_LINK,
		self::SOURCE_WIDGET,
		self::SOURCE_UNKNOWN,
	];

	public const DEVICE_DESKTOP = 'desktop';
	public const DEVICE_MOBILE = 'mobile';
	public const DEVICE_OTHER = 'other';

	public const TYPE_EVENT = 'event';
	public const TYPE_STATISTICS = 'statistics';
	public const TYPE_FEEDBACK = 'feedback';
	public const TYPE_OTHER = 'other';

	private const EVENT_TEMPLATE_IDS = [
		'event_registration',
		'offline_event',
		'online_event',
		'offline_registration_event',
	];

	public function classifySource(?string $hint, array $context): string
	{
		$contextSource = $this->normalizeCode($context['source'] ?? null);
		if (in_array($contextSource, self::SOURCE_CODES, true))
		{
			return $contextSource;
		}

		$hint = $this->normalizeCode($hint);

		return in_array($hint, self::SOURCE_CODES, true) ? $hint : self::SOURCE_UNKNOWN;
	}

	public function classifyDevice(?string $userAgent): string
	{
		$userAgent = trim((string)$userAgent);
		if ($userAgent === '')
		{
			return self::DEVICE_OTHER;
		}

		if (preg_match('/android.*mobile|iphone|ipod|windows phone|blackberry|opera mini|mobile/i', $userAgent))
		{
			return self::DEVICE_MOBILE;
		}

		if (preg_match('/windows nt|macintosh|x11|linux x86_64|cros/i', $userAgent))
		{
			return self::DEVICE_DESKTOP;
		}

		return self::DEVICE_OTHER;
	}

	public function classifyType(?string $templateId): string
	{
		$templateId = $this->normalizeCode($templateId);
		if (in_array($templateId, self::EVENT_TEMPLATE_IDS, true))
		{
			return self::TYPE_EVENT;
		}

		return $templateId === self::TYPE_FEEDBACK ? self::TYPE_FEEDBACK : self::TYPE_OTHER;
	}

	public function isFullyFilled(array $fields): bool
	{
		foreach ($fields as $field)
		{
			if (!is_array($field) || $this->isExcludedField($field))
			{
				continue;
			}

			if (!array_key_exists('value', $field) || $this->isEmptyValue($field['value']))
			{
				return false;
			}
		}

		return true;
	}

	private function isExcludedField(array $field): bool
	{
		$type = $this->normalizeCode($field['type'] ?? null);
		if ($type === 'hidden' || FieldTable::isUiFieldType($type))
		{
			return true;
		}

		if ($this->readFlag($field, 'hidden') || !$this->readFlag($field, 'visible', true))
		{
			return true;
		}

		if (!$this->readFlag($field, 'editable', true))
		{
			return true;
		}

		return $this->readFlag($field, 'service') && $this->readFlag($field, 'prefilled');
	}

	private function isEmptyValue(mixed $value): bool
	{
		if ($value === null || $value === false)
		{
			return true;
		}

		if (is_string($value))
		{
			return trim($value) === '';
		}

		if (is_array($value))
		{
			foreach ($value as $item)
			{
				if (!$this->isEmptyValue($item))
				{
					return false;
				}
			}

			return true;
		}

		return false;
	}

	private function readFlag(array $field, string $key, bool $default = false): bool
	{
		if (!array_key_exists($key, $field))
		{
			return $default;
		}

		return in_array($field[$key], [true, 1, '1', 'Y', 'y'], true);
	}

	private function normalizeCode(mixed $code): string
	{
		return is_string($code) ? strtolower(trim($code)) : '';
	}
}
