<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\ActionFilter\Access;

use Bitrix\Main\Access\AccessibleController;

interface AccessCheckStrategyInterface
{
	/**
	 * Creates a strategy instance configured with the given access controller and arguments.
	 *
	 * @param AccessibleController $accessController
	 * @param array $config Configuration from #[ActionAccess] strategyArgs
	 */
	public static function create(
		AccessibleController $accessController,
		array $config,
	): static;

	/**
	 * Checks access for the given action and request data.
	 *
	 * @param string $action Action identifier from ActionDictionary
	 * @param array $requestData All request parameters
	 */
	public function check(
		string $action,
		array $requestData,
	): bool;
}
