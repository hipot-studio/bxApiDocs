<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Settings;

use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SettingSchemaInterface;
use Bitrix\Main\ArgumentException;

/**
 * @template TValue
 * @internal
 */
final class SettingDescription
{
	/**
	 * @param SettingSchemaInterface<TValue> $schema
	 * @throws ArgumentException
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		/** @var SettingSchemaInterface<TValue> */
		public readonly SettingSchemaInterface $schema,
		public readonly ?string $description = null,
		public readonly ?string $note = null,
		public readonly ?string $optionKey = null,
	)
	{
		if ($this->id === '')
		{
			throw new ArgumentException('Id must not be empty', 'id');
		}

		if ($this->title === '')
		{
			throw new ArgumentException('Title must not be empty', 'title');
		}
	}
}
