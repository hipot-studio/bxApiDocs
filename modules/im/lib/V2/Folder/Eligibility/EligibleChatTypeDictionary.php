<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Eligibility;

use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Common\FormatConverter;

/**
 * Client projection of {@see EligibleChatTypes}: the values with which web and mobile label a
 * chat. The base value is the camelCase extended type - exactly the string the server already
 * puts into the 'type' field of a chat.
 *
 * One exception, the one-to-one dialog: web holds it as 'user' and has no 'private' in its
 * ChatType enum, while mobile holds both values and uses them as a pair.
 *
 * Dynamic entity types of the 'C' and 'O' literals stay out of both lists: they are allowed by the
 * rule but are not registered types, so a snapshot of strings cannot express them. The client ends
 * up stricter than the server, which is accepted.
 */
final class EligibleChatTypeDictionary
{
	private const WEB_PRIVATE_VALUE = 'user';

	/**
	 * @return string[]
	 */
	public static function forWeb(): array
	{
		$values = [];
		foreach (self::getExtendedTypeValues() as $value)
		{
			$values[] = $value === self::getPrivateValue() ? self::WEB_PRIVATE_VALUE : $value;
		}

		return array_values(array_unique($values));
	}

	/**
	 * @return string[]
	 */
	public static function forMobile(): array
	{
		$values = self::getExtendedTypeValues();
		if (in_array(self::getPrivateValue(), $values, true))
		{
			$values[] = self::WEB_PRIVATE_VALUE;
		}

		return array_values(array_unique($values));
	}

	/**
	 * @return string[]
	 */
	private static function getExtendedTypeValues(): array
	{
		$values = [];
		foreach (EligibleChatTypes::getTypeCondition()->include ?? [] as $type)
		{
			$values[$type->getExtendedType()] = true;
		}

		return array_keys($values);
	}

	private static function getPrivateValue(): string
	{
		return FormatConverter::toCamelCase(ExtendedType::Private->value);
	}
}
