<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Catalog;

/**
 * The state of the portal a right of the catalog is asked in.
 *
 * The catalog has no such notion of its own: the states are the branches its own access controller
 * takes before a rule of an action is ever reached, named here so that a caller can tell them apart
 * instead of reading one answer for three different things.
 *
 * @internal
 */
enum CatalogPermissionState
{
	/** The right of the action is measured, and the answer is about the user it was asked for. */
	case Regular;

	/**
	 * The rights of the catalog are being installed. Until that ends the controller answers by the
	 * legacy operations of whoever the process runs as, so an answer about another user is impossible
	 * to get and none should be believed.
	 */
	case CatalogRightsMigration;

	/**
	 * The catalog of the portal lives outside it. Reading it is then allowed to everybody, and the
	 * controller says so before any rule of the action is asked.
	 */
	case ExternalCatalog;

	/**
	 * The rights editor of the catalog is off on this plan. The rule of the action then measures the
	 * departments of the user instead of the action itself, so the answer is about belonging to the
	 * company and not about the right that was asked for.
	 */
	case AccessChecksDisabled;
}
