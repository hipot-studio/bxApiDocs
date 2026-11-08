<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock;
use Bitrix\Im\V2\Registry;

/**
 * @implements \IteratorAggregate<int,AbstractBlock>
 * @method AbstractBlock offsetGet($key)
 */
class BlockCollection extends Registry implements \JsonSerializable
{
	public function jsonSerialize(): array
	{
		$result = [];

		foreach ($this as $block)
		{
			$result[] = self::addServiceFlag($block, $block->jsonSerialize());
		}

		return $result;
	}

	public function toArray(): array
	{
		$result = [];

		foreach ($this as $block)
		{
			$result[] = self::addServiceFlag($block, $block->toArray());
		}

		return $result;
	}

	/**
	 * Serialization of a block sent on its own, outside a collection - a pull event of a single element.
	 * The flag has to reach the client through that channel too: an element returned back to
	 * Element.update replaces the stored one, and the same element must not mean two different things
	 * depending on the channel it came from.
	 */
	public static function serializeBlock(?AbstractBlock $block): ?array
	{
		if ($block === null)
		{
			return null;
		}

		return self::addServiceFlag($block, $block->jsonSerialize());
	}

	public function getPayloadText(): string
	{
		$result = '';
		foreach ($this as $block)
		{
			if ($block->isService())
			{
				continue;
			}

			$text = $block->getPayloadText();
			if (!empty($text))
			{
				$result .= $text . PHP_EOL;
			}
		}

		return $this->filterPayloadText(trim($result));
	}

	/**
	 * @param array<int, string> $namesById
	 */
	public function fillFileNames(array $namesById): void
	{
		foreach ($this as $block)
		{
			$block->fillFileNames($namesById);
		}
	}

	/**
	 * The flag belongs to every block type, so it is merged here instead of being repeated in each
	 * block serialization. Blocks without the flag keep their payload untouched.
	 */
	protected static function addServiceFlag(AbstractBlock $block, array $blockPayload): array
	{
		if ($block->isService())
		{
			$blockPayload[Field::IsService->value] = true;
		}

		return $blockPayload;
	}

	protected function filterPayloadText(string $text): string
	{
		return preg_replace("/\[SOURCE=(\d+)](.*?)\[\/SOURCE]/is", "\$2", $text);
	}

	public function getLastBlock(): ?AbstractBlock
	{
		$result = null;

		foreach ($this as $block)
		{
			$result = $block;
		}

		return $result;
	}

	public function getById(string $id): ?AbstractBlock
	{
		foreach ($this as $block)
		{
			if ($block->getId() === $id)
			{
				return $block;
			}
		}

		return null;
	}

	public function getFiles(): array
	{
		$files = [];
		foreach ($this as $block)
		{
			$files[] = $block->getFiles();
		}

		return array_merge(...$files);
	}

	/**
	 * @return int[]
	 */
	public function getFilesOfType(BlockType $type): array
	{
		$files = [];
		foreach ($this as $block)
		{
			if ($block::getType() === $type)
			{
				$files[] = $block->getFiles();
			}
		}

		return array_merge(...$files);
	}
}
