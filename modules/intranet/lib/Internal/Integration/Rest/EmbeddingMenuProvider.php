<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingMenuItemDto;
use Bitrix\Rest\Public\Provider\Embedding;

final class EmbeddingMenuProvider
{
	private ?bool $isAvailable = null;

	public function isAvailable(): bool
	{
		return $this->isAvailable ??= Loader::includeModule('rest');
	}

	/**
	 * @param string[] $placements
	 * @return EmbeddingMenuItemDto[]
	 */
	public function getBindingItems(array $placements): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		return (new Embedding\EmbeddingMenuProvider())->getBindingItems($placements);
	}

	/**
	 * @param string $placement
	 * @return EmbeddingMenuItemDto[]
	 */
	public function getLeftMenuItems(string $placement): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		return (new Embedding\EmbeddingMenuProvider())->getLeftMenuItems($placement);
	}
}
