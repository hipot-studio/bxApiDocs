<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Location;

use Bitrix\Intranet\Internal\Entity\User\Field\ConvertableToUserFieldValue;
use Bitrix\Location\Entity\Address;
use Bitrix\Location\Service\FormatService;
use Bitrix\Location\Entity\Address\Converter\StringConverter;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Contract\Arrayable;
use Stringable;

class AddressAdapter implements ConvertableToUserFieldValue, Stringable, Arrayable
{
	private Address $address;

	public function __construct(Address $address)
	{
		$this->address = $address;
	}

	public function __toString(): string
	{
		if (!Loader::includeModule('location'))
		{
			return '';
		}

		try
		{
			return $this->address->toString(
				FormatService::getInstance()->findDefault(LANGUAGE_ID),
				StringConverter::STRATEGY_TYPE_TEMPLATE_COMMA,
			);
		}
		catch (ArgumentOutOfRangeException)
		{
			return '';
		}
	}

	public function toArray(): array
	{
		return $this->address->toArray();
	}

	public function toUserFieldValue(): string
	{
		if (!Loader::includeModule('location'))
		{
			return '';
		}

		try
		{
			return $this->address->toJson();
		}
		catch (ArgumentException)
		{
			return '';
		}
	}
}
