<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Settings;

use Bitrix\Main\ArgumentException;

/**
 * @template TValue
 * @internal
 */
final class SettingOption
{
	/**
	 * @param TValue $value
	 * @throws ArgumentException
	 */
	public function __construct(
		/** @var TValue */
		public readonly mixed $value,
		public readonly string $title,
		public readonly ?string $hint = null,
	)
	{
		if ($this->title === '')
		{
			throw new ArgumentException('Title must not be empty', 'title');
		}
	}
}
