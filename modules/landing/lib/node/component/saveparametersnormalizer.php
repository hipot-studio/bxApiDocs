<?php
namespace Bitrix\Landing\Node\Component;

use Bitrix\Landing\Block;
use Bitrix\Landing\Node\Component\Store\AddToBasketActionSaveNormalizer;

final class SaveParametersNormalizer
{
	/**
	 * @return ISaveParameterNormalizer[]
	 */
	private static function getNormalizers(): array
	{
		return [
			new AddToBasketActionSaveNormalizer(),
		];
	}

	public static function normalize(
		Block $block,
		string $selector,
		array $updateProps,
		array $allowedProps
	): array
	{
		foreach (self::getNormalizers() as $normalizer)
		{
			if ($normalizer->supports($block, $selector, $updateProps, $allowedProps))
			{
				$updateProps = $normalizer->normalize($block, $selector, $updateProps, $allowedProps);
			}
		}

		return $updateProps;
	}
}
