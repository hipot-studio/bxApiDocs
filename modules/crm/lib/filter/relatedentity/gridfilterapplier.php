<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

use Bitrix\Crm\Service\Container;
use CCrmCompany;
use CCrmContact;
use CCrmDeal;
use CCrmLead;
use CCrmOwnerType;
use CCrmQuote;

/**
 * Applies the RELATED_ENTITIES filter to grid query parameters, hiding the
 * ORM-vs-legacy dispatch and the source table alias resolution behind a single
 * entry point.
 *
 * Both the list rendering path (list components, kanban, dynamic item list) and
 * the group-action path (Controller\Autorun\Base, "for all" mode) go through
 * this helper, so the EXISTS predicate is applied consistently no matter how the
 * grid filter reaches the query.
 */
final class GridFilterApplier
{
	public function __construct(private readonly FilterApplier $applier)
	{
	}

	public static function getDefault(): self
	{
		return new self(
			new FilterApplier(Container::getInstance()->getRelatedEntityFilterRegistry())
		);
	}

	/**
	 * Mutates $parameters in place: reads RELATED_ENTITIES from $parameters['filter']
	 * and injects the resulting condition. For legacy sources the predicate is added
	 * as an $parameters['filter']['__CONDITIONS'] SQL fragment; for ORM sources it is
	 * added as a runtime field plus a filter entry ($parameters['runtime']).
	 */
	public function apply(array &$parameters, int $sourceTypeId): void
	{
		$alias = self::resolveLegacyTableAlias($sourceTypeId);
		if ($alias === null)
		{
			// ORM sources (SmartInvoice, dynamic types) reach the query through
			// Service\Factory::getItems() and use a runtime field expression.
			$this->applier->applyToOrmParameters($parameters, $sourceTypeId);

			return;
		}

		if (!isset($parameters['filter']) || !is_array($parameters['filter']))
		{
			return;
		}

		$this->applier->applyToLegacyFilter($parameters['filter'], $sourceTypeId, $alias);
	}

	/**
	 * Returns the SQL table alias for legacy sources handled through
	 * CCrm<Entity>::GetListEx, or null for ORM (factory-based) sources.
	 */
	public static function resolveLegacyTableAlias(int $entityTypeId): ?string
	{
		return match ($entityTypeId)
		{
			CCrmOwnerType::Lead => CCrmLead::TABLE_ALIAS,
			CCrmOwnerType::Deal => CCrmDeal::TABLE_ALIAS,
			CCrmOwnerType::Contact => CCrmContact::TABLE_ALIAS,
			CCrmOwnerType::Company => CCrmCompany::TABLE_ALIAS,
			CCrmOwnerType::Quote => CCrmQuote::TABLE_ALIAS,
			default => null,
		};
	}
}
