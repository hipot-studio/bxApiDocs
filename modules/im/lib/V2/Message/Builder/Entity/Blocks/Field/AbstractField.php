<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Result;

abstract class AbstractField
{
	protected const MAX_TEXT_LENGTH = 20000;

	protected static array $instances = [];

	abstract public function validate(mixed $field, ?BlockType $blockType = null): Result;

	protected function __construct()
	{}

	public static function getInstance(): AbstractField
	{
		static::$instances[static::class] ??= new static();

		return static::$instances[static::class];
	}
}
