<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item;

use Bitrix\Crm\Item;
use Bitrix\Crm\UtmTable;

final class LegacyFieldNameResolver
{
	private const MULTIFIELD_LEGACY_NAMES = [
		'phones' => 'PHONE',
		'emails' => 'EMAIL',
		'webs' => 'WEB',
		'ims' => 'IM',
	];

	/**
	 * @return list<string>
	 */
	public static function resolve(string $fieldId, FieldRegistry $registry): array
	{
		if (str_starts_with($fieldId, 'UF_'))
		{
			return [$fieldId];
		}
		if (str_starts_with($fieldId, 'parentId_'))
		{
			return ['PARENT_ID_' . substr($fieldId, strlen('parentId_'))];
		}

		$descriptor = $registry->getField($fieldId);
		if ($descriptor === null)
		{
			return [];
		}

		return match ($descriptor->type)
		{
			FieldType::Utm => UtmTable::getCodeList(),
			FieldType::MultifieldCollection => isset(self::MULTIFIELD_LEGACY_NAMES[$descriptor->name])
				? [self::MULTIFIELD_LEGACY_NAMES[$descriptor->name]]
				: [],
			FieldType::ObserverCollection => [Item::FIELD_NAME_OBSERVERS],
			FieldType::ContactBindingCollection => [Item::FIELD_NAME_CONTACT_BINDINGS],
			default => [$registry->getLegacyFieldName($descriptor->ormName)],
		};
	}
}
