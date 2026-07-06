<?php
namespace Bitrix\Landing\Node\Component;

use Bitrix\Landing\Block;

interface ISaveParameterNormalizer
{
	public function supports(
		Block $block,
		string $selector,
		array $updateProps,
		array $allowedProps
	): bool;

	public function normalize(
		Block $block,
		string $selector,
		array $updateProps,
		array $allowedProps
	): array;
}
