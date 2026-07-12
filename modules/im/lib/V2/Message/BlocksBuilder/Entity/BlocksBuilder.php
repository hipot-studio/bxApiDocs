<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity;

use Bitrix\Im\Common;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock;

class BlocksBuilder implements \JsonSerializable
{
	protected ?string $json = null;
	protected ?array $arrayValue = null;
	protected ?array $serializeValue = null;
	protected ?array $files = null;

	public function __construct(
		public readonly Config $config,
		public readonly BlockCollection $blockCollection,
	)
	{}

	public function jsonSerialize(): array
	{
		if ($this->serializeValue === null)
		{
			$this->serializeValue = [
				'config' => $this->config->jsonSerialize(),
				'elements' => $this->blockCollection->jsonSerialize(),
			];
		}

		return $this->serializeValue;
	}

	public function toArray(): array
	{
		if ($this->arrayValue === null)
		{
			$this->arrayValue = [
				'config' => $this->config->toArray(),
				'elements' => $this->blockCollection->toArray(),
			];
		}

		return $this->arrayValue;
	}

	public function getFiles(): array
	{
		if ($this->files === null)
		{
			$files = $this->blockCollection->getFiles();
			$this->files = $files;
		}

		return $this->files;
	}

	public function getPayloadText(): string
	{
		$text = $this->blockCollection->getPayloadText();
		if ($text === '')
		{
			$text = BuilderService::getPlaceholder();
		}

		return $text;
	}

	public function getLastBlock(): ?AbstractBlock
	{
		return $this->blockCollection->getLastBlock();
	}

	public function getBlockById(string $id): ?AbstractBlock
	{
		return $this->blockCollection->getById($id);
	}

	public function getJson(): string
	{
		$this->json ??= Common::jsonEncode($this->toArray());

		return $this->json;
	}
}
