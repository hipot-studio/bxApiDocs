<?php

namespace Bitrix\Crm\Binding;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\NotSupportedException;
use CCrmOwnerType;

/**
 * Restricts a contact (company) list to the items bound to the specified owner.
 * The restriction is a single INNER JOIN of the binding table by the owner side index.
 */
final class OwnerBindingFilter
{
	/** Alias of the binding table in the join. It differs from the aliases of ASSOCIATED_* filters */
	public const JOIN_ALIAS = 'CTBO';

	private const KIND_DEAL = 'deal';
	private const KIND_LEAD = 'lead';
	private const KIND_QUOTE = 'quote';
	private const KIND_COMPANY_CONTACTS = 'companyContacts';
	private const KIND_CONTACT_COMPANIES = 'contactCompanies';
	private const KIND_ENTITY_CONTACT = 'entityContact';

	public static function isSupported(int $childEntityTypeId, int $ownerEntityTypeId): bool
	{
		return self::resolveKind($childEntityTypeId, $ownerEntityTypeId) !== null;
	}

	/**
	 * @throws NotSupportedException
	 */
	public static function prepareJoinSql(int $childEntityTypeId, ItemIdentifier $owner, string $tableAlias): string
	{
		$ownerEntityTypeId = $owner->getEntityTypeId();
		$ownerId = $owner->getEntityId();

		$sql = match (self::resolveKindOrThrow($childEntityTypeId, $ownerEntityTypeId))
		{
			self::KIND_DEAL => DealContactTable::prepareFilterJoinSql(CCrmOwnerType::Deal, $ownerId, $tableAlias),
			self::KIND_LEAD => LeadContactTable::prepareFilterJoinSql(CCrmOwnerType::Lead, $ownerId, $tableAlias),
			self::KIND_QUOTE => QuoteContactTable::prepareFilterJoinSql(CCrmOwnerType::Quote, $ownerId, $tableAlias),
			self::KIND_COMPANY_CONTACTS => ContactCompanyTable::prepareFilterJoinSql(
				CCrmOwnerType::Company,
				$ownerId,
				$tableAlias,
			),
			self::KIND_CONTACT_COMPANIES => ContactCompanyTable::prepareFilterJoinSql(
				CCrmOwnerType::Contact,
				$ownerId,
				$tableAlias,
			),
			self::KIND_ENTITY_CONTACT => EntityContactTable::prepareFilterJoinSql(
				$ownerEntityTypeId,
				$ownerId,
				$tableAlias,
			),
		};

		return self::replaceJoinAlias($sql);
	}

	/**
	 * Reads the owner from the BINDING_OWNER list filter value:
	 * ['ENTITY_TYPE_ID' => int, 'ENTITY_ID' => int]. Returns null for a malformed value.
	 */
	public static function tryCreateOwnerFromFilterValue(mixed $value): ?ItemIdentifier
	{
		if (!is_array($value))
		{
			return null;
		}

		$entityTypeId = $value['ENTITY_TYPE_ID'] ?? null;
		$entityId = $value['ENTITY_ID'] ?? null;
		if (!is_numeric($entityTypeId) || !is_numeric($entityId))
		{
			return null;
		}

		$entityTypeId = (int)$entityTypeId;
		$entityId = (int)$entityId;
		if ($entityId <= 0 || !CCrmOwnerType::IsDefined($entityTypeId))
		{
			return null;
		}

		return new ItemIdentifier($entityTypeId, $entityId);
	}

	/**
	 * Returns the primary bound item: the one marked IS_PRIMARY, otherwise the first by SORT.
	 * The owner-company has no primary contact: IS_PRIMARY in b_crm_contact_company belongs to the contact.
	 *
	 * @throws NotSupportedException
	 */
	public static function getPrimaryChildId(int $childEntityTypeId, ItemIdentifier $owner): ?int
	{
		$ownerId = $owner->getEntityId();

		[$dataClass, $filter, $childField] = match (
			self::resolveKindOrThrow($childEntityTypeId, $owner->getEntityTypeId())
		)
		{
			self::KIND_COMPANY_CONTACTS => [null, [], ''],
			self::KIND_DEAL => [DealContactTable::class, ['=DEAL_ID' => $ownerId], 'CONTACT_ID'],
			self::KIND_LEAD => [LeadContactTable::class, ['=LEAD_ID' => $ownerId], 'CONTACT_ID'],
			self::KIND_QUOTE => [QuoteContactTable::class, ['=QUOTE_ID' => $ownerId], 'CONTACT_ID'],
			self::KIND_CONTACT_COMPANIES => [ContactCompanyTable::class, ['=CONTACT_ID' => $ownerId], 'COMPANY_ID'],
			self::KIND_ENTITY_CONTACT => [
				EntityContactTable::class,
				[
					'=ENTITY_TYPE_ID' => $owner->getEntityTypeId(),
					'=ENTITY_ID' => $ownerId,
				],
				'CONTACT_ID',
			],
		};

		if ($dataClass === null)
		{
			return null;
		}

		$row = $dataClass::getList([
			'select' => [$childField],
			'filter' => $filter,
			'order' => [
				'IS_PRIMARY' => 'DESC',
				'SORT' => 'ASC',
				$childField => 'ASC',
			],
			'limit' => 1,
		])->fetch();

		return $row ? (int)$row[$childField] : null;
	}

	private static function resolveKind(int $childEntityTypeId, int $ownerEntityTypeId): ?string
	{
		if ($childEntityTypeId === CCrmOwnerType::Company)
		{
			return $ownerEntityTypeId === CCrmOwnerType::Contact ? self::KIND_CONTACT_COMPANIES : null;
		}

		if ($childEntityTypeId !== CCrmOwnerType::Contact)
		{
			return null;
		}

		return match ($ownerEntityTypeId)
		{
			CCrmOwnerType::Deal => self::KIND_DEAL,
			CCrmOwnerType::Lead => self::KIND_LEAD,
			CCrmOwnerType::Quote => self::KIND_QUOTE,
			CCrmOwnerType::Company => self::KIND_COMPANY_CONTACTS,
			default => self::isClientEnabledSmartType($ownerEntityTypeId) ? self::KIND_ENTITY_CONTACT : null,
		};
	}

	/**
	 * @throws NotSupportedException
	 */
	private static function resolveKindOrThrow(int $childEntityTypeId, int $ownerEntityTypeId): string
	{
		$kind = self::resolveKind($childEntityTypeId, $ownerEntityTypeId);
		if ($kind === null)
		{
			$ownerName = CCrmOwnerType::ResolveName($ownerEntityTypeId);
			$childName = CCrmOwnerType::ResolveName($childEntityTypeId);

			throw new NotSupportedException(
				"Binding of '{$childName}' to owner '{$ownerName}' is not supported in current context"
			);
		}

		return $kind;
	}

	private static function isClientEnabledSmartType(int $entityTypeId): bool
	{
		if (
			$entityTypeId === CCrmOwnerType::SmartB2eDocument
			|| !CCrmOwnerType::isUseDynamicTypeBasedApproach($entityTypeId)
		)
		{
			return false;
		}

		return (bool)Container::getInstance()->getFactory($entityTypeId)?->isClientEnabled();
	}

	/**
	 * Existing join builders use fixed aliases (DC, CC, ...) that are also used by ASSOCIATED_* filters.
	 * The owner join gets its own alias, so both joins can be present in one query.
	 */
	private static function replaceJoinAlias(string $sql): string
	{
		if (!preg_match('/^INNER JOIN\s+\w+\s+(\w+)\s+ON\s/i', $sql, $matches))
		{
			return $sql;
		}

		$alias = $matches[1];

		return preg_replace('/(?<![\w.])' . $alias . '(?=\.|\s+ON\s)/', self::JOIN_ALIAS, $sql);
	}
}
