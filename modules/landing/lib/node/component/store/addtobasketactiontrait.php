<?php
namespace Bitrix\Landing\Node\Component\Store;

use Bitrix\Landing\Block;

trait AddToBasketActionTrait
{
	protected function getConfiguredAddToBasketAction(): string
	{
		$action = $this->params['ADD_TO_BASKET_ACTION'] ?? 'AUTO';
		if (is_array($action))
		{
			$action = reset($action);
		}
		$action = (string)$action;

		return in_array($action, ['AUTO', 'BUY', 'ADD'], true) ? $action : 'AUTO';
	}

	protected function resolveAddToBasketActionSyspageType(array $params): ?string
	{
		$block = $params['block'] ?? null;
		if (!$block instanceof Block)
		{
			return null;
		}

		return $this->getAddToBasketActionSyspageType($block);
	}

	protected function shouldUseActiveAddToBasketActionSyspages(array $params): bool
	{
		$block = $params['block'] ?? null;

		return $block instanceof Block
			&& $this->isAddToBasketActionSyspageActiveOnly($block);
	}

	protected function resolveAddToBasketActionForCartAvailability(
		string $addToBasketAction,
		bool $showCart
	): string
	{
		if (
			$addToBasketAction === 'ADD' &&
			!$showCart
		)
		{
			return 'BUY';
		}

		return $addToBasketAction === 'AUTO' ? 'BUY' : $addToBasketAction;
	}
}
