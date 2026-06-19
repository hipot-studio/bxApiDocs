<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Factory;

use Bitrix\Im\V2\Message\Builder\Entity\BlockCollection;
use Bitrix\Im\V2\Message\Builder\Entity\Builder;
use Bitrix\Im\V2\Message\Builder\Entity\Config;

class BuilderFactory
{
	public function __construct(
		protected BlockFactory $blockFactory,
	)
	{}

	public function create(array $builderData): Builder
	{
		$config = Config::create($builderData);
		$blockCollection = new BlockCollection();

		$blockCount = 1;
		foreach ($builderData['blocks'] ?? [] as $blockData)
		{
			$blockData['id'] = $blockCount;
			$blockCount++;

			$blockEntity = $this->blockFactory->create($blockData['type'] ?? '', $blockData);
			if ($blockEntity !== null)
			{
				$blockCollection->append($blockEntity);
			}
		}

		return new Builder($config, $blockCollection);
	}
}
