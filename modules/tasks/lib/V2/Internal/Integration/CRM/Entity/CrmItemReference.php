<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Entity;

use Bitrix\Main\Loader;
use CCrmOwnerType;
use CCrmOwnerTypeAbbr;

/**
 * A single UF_CRM_TASK value split into its parts. Only the families the task list renders are
 * accepted -- lead, contact, company, deal, quote, smart invoice, order and smart processes (T{hex(typeId)}) --
 * so an unsupported or malformed value is rejected before any CRM permission or data read happens.
 */
final readonly class CrmItemReference
{
	private function __construct(
		public string $value,
		public string $abbreviation,
		public int $typeId,
		public int $entityId,
		public string $typeName,
	)
	{
	}

	public static function tryFrom(mixed $value): ?self
	{
		if (!is_string($value) || !Loader::includeModule('crm'))
		{
			return null;
		}

		[$abbreviation, $rawEntityId] = array_pad(explode('_', $value, 2), 2, '');
		if (preg_match('/^[0-9]+$/D', $rawEntityId) !== 1)
		{
			return null;
		}

		$entityId = (int)$rawEntityId;
		if ($entityId <= 0)
		{
			return null;
		}

		$typeId = CCrmOwnerTypeAbbr::ResolveTypeID($abbreviation);
		if (!self::isSupportedType($typeId))
		{
			return null;
		}

		return new self($value, $abbreviation, $typeId, $entityId, CCrmOwnerType::ResolveName($typeId));
	}

	public static function makeKey(int $typeId, int $entityId): string
	{
		return $typeId . ':' . $entityId;
	}

	/**
	 * Identity of the referenced CRM element. Two values that differ only in formatting (`D_7`, `D_007`)
	 * share one key, so rights and data are read for them once.
	 */
	public function key(): string
	{
		return self::makeKey($this->typeId, $this->entityId);
	}

	private static function isSupportedType(int $typeId): bool
	{
		$staticTypeIds = [
			CCrmOwnerType::Lead,
			CCrmOwnerType::Contact,
			CCrmOwnerType::Company,
			CCrmOwnerType::Deal,
			CCrmOwnerType::Quote,
			CCrmOwnerType::SmartInvoice,
			CCrmOwnerType::Order,
		];

		return in_array($typeId, $staticTypeIds, true) || CCrmOwnerType::isPossibleDynamicTypeId($typeId);
	}
}
