<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

use Bitrix\Intranet\Internal\Integration\Location\AddressProvider;
use Bitrix\Intranet\Internal\Integration\Location\AddressAdapter;

class AddressField extends SingleField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		public readonly bool $isVisible,
		public readonly mixed $value = null,
	)
	{
	}

	protected static function parseSingleValue(mixed $value): mixed
	{
		if (is_string($value) && AddressProvider::isAvailable())
		{
			$value = (new AddressProvider())->getByUserFieldValue($value);
		}

		return $value;
	}

	public function isValid(mixed $value): bool
	{
		return AddressProvider::isAvailable() && $value instanceof AddressAdapter;
	}
}
