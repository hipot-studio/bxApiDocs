<?php

namespace Bitrix\Sale;

use Bitrix\Main\Localization\Loc;

class EventLogAuditTypeRepository
{
	private const AUDIT_TYPES = [
		'SALE_DELIVERY_CREATE_OBJECT_ERROR',
		'SALE_DELIVERY_YANDEX_TAXI',
	];

	public static function getAuditTypes(): array
	{
		$result = [];

		foreach (self::AUDIT_TYPES as $auditType)
		{
			$result[$auditType] = self::getName($auditType);
		}

		return $result;
	}

	private static function getName(string $auditType): string
	{
		return '[' . $auditType . '] ' . Loc::getMessage('SALE_AUDIT_TYPE_' . $auditType);
	}
}
