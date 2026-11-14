<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Binding;

use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;

/**
 * Which types one of the bound contacts can be named the primary one of.
 *
 * The single source of the rule: the command handler
 * {@see \Bitrix\Crm\V2\Internal\Service\Item\Handler\SetPrimaryContactCommandHandler} answers a call
 * with it, and
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider::hasSetPrimaryContactSupport()}
 * publishes the routes of `crm.{entity}.setPrimaryContact` by it.
 *
 * @internal
 */
final class PrimaryContactSupport
{
	/**
	 * The company answers true to {@see EntityTypeSettings::hasContactBindings()} and is still excluded:
	 * its bindings are stored in `b_crm_contact_company`, where `IS_PRIMARY` says that the company is the
	 * primary one *of the contact* (LBS section 7). A stored "primary contact of a company" is not a thing
	 * the portal has, and one row serves both directions of the link.
	 *
	 * The flag itself is about real bindings and not about the legacy `Client` field - see the note of
	 * {@see EntityTypeSettings::createCompanySettings()} - so it is no place to carry the exclusion.
	 */
	private const EXCLUDED_ENTITY_TYPE_IDS = [
		EntityType::COMPANY,
	];

	public static function isSupported(EntityType $entityType): bool
	{
		if (in_array($entityType->getId(), self::EXCLUDED_ENTITY_TYPE_IDS, true))
		{
			return false;
		}

		return EntityTypeSettings::of($entityType)->hasContactBindings();
	}
}
