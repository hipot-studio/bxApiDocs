<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;

class PublishLandingSiteDto
{
	public const ACTION_PUBLISH = 'publish';
	public const ACTION_UNPUBLISH = 'unpublish';

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $siteId = null,
		#[NotEmpty]
		public readonly ?string $action = null,
		public readonly bool $confirm = false,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			siteId: self::mapInteger($props, 'siteId'),
			action: self::mapString($props, 'action'),
			confirm: self::mapBool($props, 'confirm') ?? false,
		);
	}

	public function isValidAction(): bool
	{
		return in_array($this->action, [self::ACTION_PUBLISH, self::ACTION_UNPUBLISH], true);
	}

	public function isPublishAction(): bool
	{
		return $this->action === self::ACTION_PUBLISH;
	}

	public function getStatus(): string
	{
		return $this->isPublishAction() ? 'published' : 'unpublished';
	}

	private static function mapInteger(array $props, string $key): ?int
	{
		if (!array_key_exists($key, $props) || $props[$key] === null)
		{
			return null;
		}

		if (is_bool($props[$key]) || !is_scalar($props[$key]))
		{
			return null;
		}

		$value = filter_var($props[$key], FILTER_VALIDATE_INT);

		return $value !== false ? (int)$value : null;
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
