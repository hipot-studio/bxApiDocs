<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

/**
 * The pair the tariff plate carries: the action its button calls to and the scenario the tariff table
 * is opened with.
 */
final class TariffPurchaseDecision
{
	public function __construct(
		public readonly TariffPurchaseAction $action,
		public readonly TariffTableSelection $selection,
	)
	{
	}
}
