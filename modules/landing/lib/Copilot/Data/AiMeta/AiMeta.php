<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Data\AiMeta;

use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;

final class AiMeta
{
	public function __construct(
		private AiMetaImages $images = new AiMetaImages(),
		private AiMetaNodes $nodes = new AiMetaNodes(),
	)
	{
	}

	public static function fromArray(mixed $data): self
	{
		if (!is_array($data))
		{
			return new self();
		}

		$normalized = AiBlockHtmlPayloadProcessor::normalizeAiMeta($data);

		return new self(
			images: AiMetaImages::fromArray($normalized['images']),
			nodes: AiMetaNodes::fromArray($normalized['nodes']),
		);
	}

	public function toArray(): array
	{
		return [
			'images' => $this->images->toArray(),
			'nodes' => $this->nodes->toArray(),
		];
	}

	public function getImages(): AiMetaImages
	{
		return $this->images;
	}

	public function setImages(AiMetaImages $images): void
	{
		$this->images = $images;
	}

	public function getNodes(): AiMetaNodes
	{
		return $this->nodes;
	}

	public function setNodes(AiMetaNodes $nodes): void
	{
		$this->nodes = $nodes;
	}
}
