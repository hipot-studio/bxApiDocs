<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Data\AiMeta;

use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;

final class AiMetaImages
{
	/** @var list<AiMetaImage> */
	private array $images = [];

	/**
	 * @param list<AiMetaImage> $images
	 */
	public function __construct(array $images = [])
	{
		$this->images = $images;
	}

	public static function fromArray(mixed $data): self
	{
		$normalized = AiBlockHtmlPayloadProcessor::normalizeAiMeta(['images' => is_array($data) ? $data : []]);
		$images = [];
		foreach ($normalized['images'] as $image)
		{
			if (!is_array($image))
			{
				continue;
			}

			$images[] = AiMetaImage::fromArray($image);
		}

		return new self($images);
	}

	public static function fromAiMetaArray(mixed $aiMeta): self
	{
		if (!is_array($aiMeta))
		{
			return new self();
		}

		return self::fromArray($aiMeta['images'] ?? null);
	}

	public function toArray(): array
	{
		return array_map(static fn(AiMetaImage $image): array => $image->toArray(), $this->images);
	}

	/**
	 * @return list<AiMetaImage>
	 */
	public function getImages(): array
	{
		return $this->images;
	}
}
