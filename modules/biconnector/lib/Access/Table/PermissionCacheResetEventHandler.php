<?php

namespace Bitrix\BIConnector\Access\Table;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\ExternalSource\SupersetServiceIntegration;
use Bitrix\Main\Event;

/**
 * Tells the notifier that the permission set of somebody may have changed (EVENT-01).
 *
 * The handlers read no rights and compute no set: they only pass the message on. They hang on mass core
 * events, so each one has to stay cheap - a comparison of field keys and a call, no queries.
 *
 * Why core events at all: the roles of a person are the intersection of their access codes with the role
 * relation records, so a set changes without a single row of this module being touched - a transfer
 * between departments, an edit of the user groups. Without these subscriptions the set at the consumer
 * is not stale but wrong, and the safety lifetime turns from the last line into the mechanism by which
 * rights are applied, which it must not be.
 */
final class PermissionCacheResetEventHandler
{
	/**
	 * The classic user form goes through CUser::Update, and the compatibility layer of the org structure
	 * removes its own member handlers for the duration of that sync - so the member events alone leave
	 * this path uncovered and both subscriptions are needed.
	 *
	 * ACTIVE is here for a reason of its own, and not the one that reads naturally: deactivation does not
	 * empty the set. The access codes of the person survive it - the provider filters by the activity of
	 * the group and not of the person - so the resolver goes on answering what it answered before, and
	 * what actually closes the data is the deactivation of the Superset account, done on this same event
	 * by Integration\Superset\Events\Main\User. Accepted as the carrier rather than doubled by a check
	 * of ACTIVE in the resolver. The reset is asked here all the same: an account comes back on this
	 * event too, and the old value is not examined - an extra reset is idempotent and costs nothing.
	 *
	 * The early exits of the neighbouring handler of this event are deliberately not repeated: neither
	 * "the user exists in Superset" nor "Superset is ready" is a condition here, because the set changes
	 * regardless of both.
	 */
	public static function onAfterUserUpdate(array $fields): void
	{
		$isDepartmentChanged = array_key_exists('UF_DEPARTMENT', $fields) && !empty($fields['RESULT']);
		$isActiveChanged = array_key_exists('ACTIVE', $fields);

		if (!$isDepartmentChanged && !$isActiveChanged)
		{
			return;
		}

		PermissionCacheResetNotifier::notifyChanged();
	}

	/**
	 * A change of the licence: the tariff half of the axis moves, and it has no hook of its own.
	 *
	 * The axis is the conjunction of the tariff and the rollout flag, and the flag calls its hooks for
	 * its own half only. A tariff that turns the other half over switches the axis silently: upward the
	 * set kept by the consumer goes on opening data the matrix was never applied to, downward it goes on
	 * restricting after the restrictions are gone.
	 *
	 * Forced, because the predicate is read after the change: where the tariff turned the axis off, the
	 * ordinary path would ask the axis, read "off" and stay silent - in exactly the case that has to
	 * reach the consumer.
	 *
	 * While the axis is not rolled out nothing is asked: the conjunction reads false on both sides of
	 * such a change, and a forced reset there would install the retry agent on portals that have no axis.
	 */
	public static function onBitrix24LicenseChange(): void
	{
		if (!Feature::isTablePermissionsRolledOut())
		{
			return;
		}

		PermissionCacheResetNotifier::notifyChanged(true);
	}

	/**
	 * Membership in a node of the org structure: added, changed role inside it, excluded.
	 */
	public static function onMemberChanged(Event $event): void
	{
		PermissionCacheResetNotifier::notifyChanged();
	}

	/**
	 * The composition of the user groups is one of the sources of the access codes.
	 */
	public static function onAfterSetUserGroup(): void
	{
		PermissionCacheResetNotifier::notifyChanged();
	}

	/**
	 * Creation, change and deletion of an external source: the catalog of tables changes, and with it the
	 * set of anybody who is allowed the group the new table joins.
	 */
	public static function onDatasetChanged(): void
	{
		// The catalog is memoized for the hit, and a table that appeared or went away is exactly what
		// makes that memo wrong: the same request goes on answering for a catalog that is gone.
		SupersetServiceIntegration::clearCache();

		PermissionCacheResetNotifier::notifyChanged();
	}
}
