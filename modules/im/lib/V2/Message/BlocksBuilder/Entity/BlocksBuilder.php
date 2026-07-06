<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock;

class BlocksBuilder implements \JsonSerializable
{
	public function __construct(
		public readonly Config $config,
		public readonly BlockCollection $blockCollection,
	)
	{}

	public function jsonSerialize(): array
	{
		$result = $this->config->jsonSerialize();
		$result['blocks'] = $this->blockCollection->jsonSerialize();

		return $result;
	}

	public function toArray(): array
	{
		$result = $this->config->toArray();
		$result['blocks'] = $this->blockCollection->toArray();

		return $result;
	}

	public function getPayloadText(): string
	{
		return $this->blockCollection->getPayloadText();
	}

	public function getLastBlock(): ?AbstractBlock
	{
		return $this->blockCollection->getLastBlock();
	}

	public function getBlockById(string $id): ?AbstractBlock
	{
		return $this->blockCollection->getById($id);
	}
}
