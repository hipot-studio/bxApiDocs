<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\ActionFilter\Access;

use Bitrix\Main\Access\AccessibleController;

class ItemIdFromRequestStrategy implements AccessCheckStrategyInterface
{
	private function __construct(
		private readonly AccessibleController $accessController,
		private readonly string $itemIdRequestKey,
	)
	{
	}

	public static function create(
		AccessibleController $accessController,
		array $config,
	): static
	{
		return new static(
			accessController: $accessController,
			itemIdRequestKey: $config['itemIdRequestKey'] ?? 'id',
		);
	}

	public function check(
		string $action,
		array $requestData,
	): bool
	{
		$value = $requestData[$this->itemIdRequestKey] ?? null;
		$itemId = is_numeric($value) ? (int)$value : null;

		return $this->accessController->checkByItemId($action, $itemId);
	}
}
