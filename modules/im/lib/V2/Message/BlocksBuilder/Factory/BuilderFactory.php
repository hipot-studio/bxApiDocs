<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Factory;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlocksBuilder;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Config;
use Bitrix\Main\Security\Random;

class BuilderFactory
{
	public function __construct(
		protected BlockFactory $blockFactory,
	)
	{}

	public function create(array $builderData): BlocksBuilder
	{
		$config = Config::create($builderData);
		$blockCollection = new BlockCollection();

		foreach ($builderData['blocks'] ?? [] as $blockData)
		{
			if (!isset($blockData['id']) || !$this->isUniqueId((string)$blockData['id'], $blockCollection))
			{
				$blockData['id'] = $this->generateRandomId();
			}

			$blockEntity = $this->blockFactory->create($blockData['type'] ?? '', $blockData);
			if ($blockEntity !== null)
			{
				$blockCollection->append($blockEntity);
			}
		}

		return new BlocksBuilder($config, $blockCollection);
	}

	private function isUniqueId(string $id, BlockCollection $blockCollection): bool
	{
		foreach ($blockCollection as $block)
		{
			if ($block->getId() === $id)
			{
				return false;
			}
		}

		return true;
	}

	private function generateRandomId(): string
	{
		return Random::getString(13, true);
	}
}
