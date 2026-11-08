<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Settings;

use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SettingSchemaInterface;
use Bitrix\Main\ArgumentException;

/**
 * @template TValue
 * @internal
 */
final class ObjectField
{
	/**
	 * @param SettingSchemaInterface<TValue> $schema
	 * @throws ArgumentException
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $title,
		/** @var SettingSchemaInterface<TValue> */
		public readonly SettingSchemaInterface $schema,
		public readonly bool $required = true,
		public readonly ?string $hint = null,
	)
	{
		if ($this->code === '')
		{
			throw new ArgumentException('Code must not be empty', 'code');
		}

		if ($this->title === '')
		{
			throw new ArgumentException('Title must not be empty', 'title');
		}
	}
}
