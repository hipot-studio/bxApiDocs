<?php
namespace Bitrix\Landing\Node\Component\Store;

use Bitrix\Landing\Block;
use Bitrix\Landing\Node\Component\ISaveParameterNormalizer;

final class AddToBasketActionSaveNormalizer implements ISaveParameterNormalizer
{
	private const ACTION = 'ADD_TO_BASKET_ACTION';
	private const PRIMARY_ACTION = 'ADD_TO_BASKET_ACTION_PRIMARY';
	private const AUTO_ACTION = 'AUTO';
	private const MANUAL_ACTIONS = ['BUY', 'ADD'];

	public function supports(
		Block $block,
		string $selector,
		array $updateProps,
		array $allowedProps
	): bool
	{
		return isset($updateProps[self::ACTION])
			&& str_starts_with($block->getCode(), 'store.');
	}

	public function normalize(
		Block $block,
		string $selector,
		array $updateProps,
		array $allowedProps
	): array
	{
		if ($updateProps[self::ACTION] === self::AUTO_ACTION)
		{
			$updateProps[self::ACTION] = self::getDynamicValue(self::ACTION);

			if (isset($allowedProps[self::PRIMARY_ACTION]))
			{
				$updateProps[self::PRIMARY_ACTION] = self::getDynamicValue(self::PRIMARY_ACTION);
			}

			return $updateProps;
		}

		if (
			isset($allowedProps[self::PRIMARY_ACTION])
			&& in_array($updateProps[self::ACTION], self::MANUAL_ACTIONS, true)
		)
		{
			$addToBasketAction = $updateProps[self::ACTION];
			$updateProps[self::ACTION] = [
				$addToBasketAction,
			];
			$updateProps[self::PRIMARY_ACTION] = [
				$addToBasketAction,
			];
		}

		return $updateProps;
	}

	private static function getDynamicValue(string $parameter): string
	{
		return '={$classBlock->get(\'' . $parameter . '\')}';
	}
}
