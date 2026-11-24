<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner\Managed;

use Bitrix\Intranet\Internal\Marketplace\Banner\RemoteStyling;

final readonly class Campaign
{
	private const SCHEMA_VERSION = 1;
	private const REQUIRED_FIELDS = [
		'schemaVersion',
		'id',
		'localizations',
		'tariffs',
		'showFrom',
		'showTo',
	];
	private const REQUIRED_LOCALIZATION_FIELDS = [
		'title',
		'description',
		'buttonLabel',
		'buttonUrl',
	];

	/**
	 * @param array<string, array{title: string, description: string, buttonLabel: string, buttonUrl: string}> $localizations
	 * @param string[] $tariffs
	 */
	public function __construct(
		public int $schemaVersion,
		public string $id,
		public array $localizations,
		public array $tariffs,
		public \DateTimeImmutable $showFrom,
		public \DateTimeImmutable $showTo,
		public ?string $backgroundImageUrl = null,
		public ?string $textColor = null,
		public ?string $buttonBackgroundColor = null,
		public ?string $buttonTextColor = null,
	)
	{
	}

	public function isActiveAt(\DateTimeInterface $moment): bool
	{
		return $moment >= $this->showFrom && $moment < $this->showTo;
	}

	public static function fromArray(array $data): ?self
	{
		foreach (self::REQUIRED_FIELDS as $field)
		{
			if (!array_key_exists($field, $data))
			{
				return null;
			}
		}

		if ($data['schemaVersion'] !== self::SCHEMA_VERSION)
		{
			return null;
		}

		if (!self::hasValidLocalizations($data['localizations']))
		{
			return null;
		}

		$styling = RemoteStyling::normalize($data);

		try
		{
			return new self(
				self::SCHEMA_VERSION,
				$data['id'],
				$data['localizations'],
				$data['tariffs'],
				self::parseDateTime($data['showFrom']),
				self::parseDateTime($data['showTo']),
				$styling['backgroundImageUrl'],
				$styling['textColor'],
				$styling['buttonBackgroundColor'],
				$styling['buttonTextColor'],
			);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @return array{
	 *     schemaVersion: int,
	 *     id: string,
	 *     localizations: array<string, array{title: string, description: string, buttonLabel: string, buttonUrl: string}>,
	 *     tariffs: string[],
	 *     showFrom: string,
	 *     showTo: string,
	 *     backgroundImageUrl: string|null,
	 *     textColor: string|null,
	 *     buttonBackgroundColor: string|null,
	 *     buttonTextColor: string|null
	 * }
	 */
	public function toArray(): array
	{
		return [
			'schemaVersion' => $this->schemaVersion,
			'id' => $this->id,
			'localizations' => $this->localizations,
			'tariffs' => $this->tariffs,
			'showFrom' => self::formatDateTime($this->showFrom),
			'showTo' => self::formatDateTime($this->showTo),
			...RemoteStyling::normalize([
				'backgroundImageUrl' => $this->backgroundImageUrl,
				'textColor' => $this->textColor,
				'buttonBackgroundColor' => $this->buttonBackgroundColor,
				'buttonTextColor' => $this->buttonTextColor,
			]),
		];
	}

	private static function parseDateTime(mixed $value): \DateTimeImmutable
	{
		if (
			!is_string($value)
			|| preg_match(
				'~^\d{4}-\d{2}-\d{2}[Tt](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:[Zz]|[+-](?:[01]\d|2[0-3]):[0-5]\d)$~D',
				$value,
			) !== 1
		)
		{
			throw new \UnexpectedValueException('Campaign date must use RFC3339 with an explicit timezone');
		}

		$date = new \DateTimeImmutable($value);
		$errors = \DateTimeImmutable::getLastErrors();
		if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
		{
			throw new \UnexpectedValueException('Invalid campaign date');
		}

		return $date->setTimezone(new \DateTimeZone('UTC'));
	}

	private static function hasValidLocalizations(mixed $localizations): bool
	{
		if (!is_array($localizations))
		{
			return false;
		}

		foreach ($localizations as $localization)
		{
			if (!is_array($localization))
			{
				return false;
			}

			foreach (self::REQUIRED_LOCALIZATION_FIELDS as $field)
			{
				if (!array_key_exists($field, $localization) || !is_string($localization[$field]))
				{
					return false;
				}
			}
		}

		return true;
	}

	private static function formatDateTime(\DateTimeImmutable $dateTime): string
	{
		return $dateTime
			->setTimezone(new \DateTimeZone('UTC'))
			->format('Y-m-d\TH:i:sP')
		;
	}
}
