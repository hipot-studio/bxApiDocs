<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\EntityPermissions\Item as ItemPermissions;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access\SummaryAvailability;

/**
 * Answers whether the summary reaches the user at all and whether the element it is asked for may
 * be read by them.
 *
 * Both questions of the feature - the measure and the rights - are answered here and nowhere else,
 * so a caller never asks CRM about them on its own and the two never part ways.
 *
 * @internal
 */
final class ClientSummaryVisibility
{
	private SummaryAvailability $availability;

	public function __construct(?SummaryAvailability $availability = null)
	{
		$this->availability = $availability ?? new SummaryAvailability();
	}

	/**
	 * The one measure of the feature, asked and not copied.
	 */
	public function isAvailableFor(int $userId): bool
	{
		return $this->availability->isAvailableFor($userId);
	}

	/**
	 * The measure together with the engine the summary of a card is opened through.
	 *
	 * Asked by what leads to that engine - the button in the header of a card, the hint pointing at it
	 * and the guard of the launch behind them - and not by the tab of the window of a call, which starts
	 * no trigger.
	 *
	 * The type of the card names the trigger the engine is asked about; the hint of the onboarding has
	 * no card and leaves it out.
	 */
	public function isLaunchable(int $userId, ?int $entityTypeId = null): bool
	{
		return $this->availability->isLaunchable($userId, $entityTypeId);
	}

	/**
	 * The right to read one element, asked at the moment it matters.
	 *
	 * It guards a launch already asked for: the card has been on the screen for a while by then, and
	 * the right may be gone (ERR-009).
	 */
	public function isElementReadable(int $userId, int $entityTypeId, int $entityId): bool
	{
		return $this->itemPermissions($userId)->canRead($entityTypeId, $entityId);
	}

	/**
	 * The one place the rights of the elements are asked of CRM.
	 *
	 * The facade is built per user, so it is not a dependency of this service and cannot be handed
	 * in through the constructor; the tests substitute it in the container of the module instead.
	 */
	private function itemPermissions(int $userId): ItemPermissions
	{
		return Container::getInstance()->getUserPermissions($userId)->item();
	}
}
