<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Configuration\Feature;

use Bitrix\BIConnector\Access\Table\PermissionCacheResetNotifier;
use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The rollout switch of the table permission axis.
 *
 * Off by default, and that default is the safety property of the axis rather than a preference: the
 * tariff half cannot carry the switch on its own - on box it is always on, and in cloud it is on for
 * every tariff where dashboard group rights already work - so an axis riding on the tariff alone would
 * switch itself on the day the code arrives, before any matrix exists.
 *
 * The flag is asked through Configuration\Feature and nowhere else: isTablePermissionsEnabled() for the
 * axis itself, isTablePermissionsRolledOut() where the question is about this half alone. The conjunction
 * with the tariff lives there too, deliberately outside this class.
 */
final class TablePermissionsFlag extends AbstractFlag
{
	/**
	 * Switching the axis on has to reach the consumers, or people go on being restricted by a set that
	 * was computed while the axis was off.
	 */
	protected function onEnable(): void
	{
		$this->forceCacheReset();
	}

	/**
	 * And switching it off has to reach them for the same reason read backwards: a long cache would go
	 * on restricting people after the restrictions were lifted. This is the direction that has no other
	 * carrier - with the axis off every ordinary write path asks the axis first and stays silent.
	 *
	 * One path off the axis does not come through here at all: Feature::resetToDefault() deletes the row
	 * and clears the value cache without calling onFlagValueChange(), so the answer of the predicate turns
	 * to the default - off - and nothing is asked of the consumers. Its only non-test caller is the CLI
	 * command main:feature:reset. Accepted rather than worked around: the set kept by the consumer expires
	 * on its own within the hour, and rights are not rebuilt often enough for that hour to be paid twice.
	 * Turning the axis off with that command and needing it immediate means asking for the reset by hand.
	 */
	protected function onDisable(): void
	{
		$this->forceCacheReset();
	}

	/**
	 * Forced, because the ordinary path returns early while the axis reads as off, and one of the two
	 * switches is always made in that state.
	 */
	private function forceCacheReset(): void
	{
		PermissionCacheResetNotifier::notifyChanged(true);
	}
}
