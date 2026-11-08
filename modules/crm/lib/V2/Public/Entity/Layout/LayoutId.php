<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class LayoutId
{
	private const PERSONAL = 'personal';
	private const COMMON = 'common';
	private const PERSONAL_RETURNING = 'personal_returning_customer';
	private const COMMON_RETURNING = 'common_returning_customer';
	private const SYSTEM_PATTERN = '/^C(0|[1-9][0-9]*):(personal|common)$/D';
	private const CUSTOM_PATTERN = '/^[1-9][0-9]*$/D';

	private function __construct(private readonly string $value)
	{
	}

	public static function fromString(string $value): Result
	{
		if (!self::isValid($value))
		{
			return (new Result())->addError(
				new Error('The layout identifier is invalid.', 'INVALID_LAYOUT_ID'),
			);
		}

		return (new Result())->setData([
			'layoutId' => new self($value),
		]);
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function isPersonal(): bool
	{
		return $this->value === self::PERSONAL
			|| $this->value === self::PERSONAL_RETURNING
			|| str_ends_with($this->value, ':' . self::PERSONAL)
		;
	}

	public function isCommon(): bool
	{
		return $this->value === self::COMMON
			|| $this->value === self::COMMON_RETURNING
			|| str_ends_with($this->value, ':' . self::COMMON)
		;
	}

	public function isCustom(): bool
	{
		return preg_match(self::CUSTOM_PATTERN, $this->value) === 1;
	}

	public function getCategoryId(): ?int
	{
		if (preg_match(self::SYSTEM_PATTERN, $this->value, $matches) !== 1)
		{
			return null;
		}

		return (int)$matches[1];
	}

	public function getCustomId(): ?int
	{
		return $this->isCustom() ? (int)$this->value : null;
	}

	public function getVariant(): LayoutVariant
	{
		return in_array($this->value, [self::PERSONAL_RETURNING, self::COMMON_RETURNING], true)
			? LayoutVariant::Returning
			: LayoutVariant::General;
	}

	public function belongsToContext(LayoutContext $context): bool
	{
		if ($this->isCustom())
		{
			return true;
		}

		if ($this->getVariant() !== $context->getVariant())
		{
			return false;
		}

		return $this->getCategoryId() === $context->getCategoryId();
	}

	private static function isValid(string $value): bool
	{
		if (in_array($value, [self::PERSONAL, self::COMMON, self::PERSONAL_RETURNING, self::COMMON_RETURNING], true))
		{
			return true;
		}

		if (preg_match(self::SYSTEM_PATTERN, $value, $matches) === 1)
		{
			return self::isInIntRange($matches[1]);
		}

		return preg_match(self::CUSTOM_PATTERN, $value) === 1 && self::isInIntRange($value);
	}

	private static function isInIntRange(string $value): bool
	{
		$max = (string)PHP_INT_MAX;

		return strlen($value) < strlen($max)
			|| (strlen($value) === strlen($max) && strcmp($value, $max) <= 0);
	}
}
