<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

abstract class AbstractField
{
	protected static array $instances = [];

	abstract public function validate(mixed $field, ?BlockType $blockType = null): Result;

	protected function __construct()
	{}

	public static function getInstance(): AbstractField
	{
		static::$instances[static::class] ??= new static();

		return static::$instances[static::class];
	}

	protected static function validateBoolValue(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		return in_array(mb_strtoupper((string)$value), ['Y', '1', 'TRUE'], true);
	}

	protected function isAllowedUrl(string $url): bool
	{
		return (bool)preg_match('~^(?:/(?!/)|https?://[^/?#]+)~i', $url);
	}
}
