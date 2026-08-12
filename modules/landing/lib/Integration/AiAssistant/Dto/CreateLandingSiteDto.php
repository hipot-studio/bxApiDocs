<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;

class CreateLandingSiteDto
{
	private function __construct(
		#[NotEmpty]
		public readonly ?string $siteTitle = null,
		public readonly ?string $pageTitle = null,
		public readonly ?string $siteCode = null,
		public readonly ?string $pageCode = null,
		public readonly ?string $description = null,
		public readonly bool $publish = false,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			siteTitle: self::mapString($props, 'siteTitle'),
			pageTitle: self::mapString($props, 'pageTitle'),
			siteCode: self::mapString($props, 'siteCode'),
			pageCode: self::mapString($props, 'pageCode'),
			description: self::mapString($props, 'description'),
			publish: self::mapBool($props, 'publish') ?? false,
		);
	}

	private static function mapString(array $props, string $key): ?string
	{
		if (!array_key_exists($key, $props))
		{
			return null;
		}

		if (!is_scalar($props[$key]))
		{
			return null;
		}

		$value = trim((string)$props[$key]);

		return $value !== '' ? $value : null;
	}

	private static function mapBool(array $props, string $key): ?bool
	{
		if (!array_key_exists($key, $props) || $props[$key] === null)
		{
			return null;
		}

		if (!is_bool($props[$key]) && !is_scalar($props[$key]))
		{
			return null;
		}

		return filter_var($props[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
	}
}
