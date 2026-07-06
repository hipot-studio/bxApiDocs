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
			$result[] = $block->jsonSerialize();
		}

		return $result;
	}

	public function toArray(): array
	{
		$result = [];

		foreach ($this as $block)
		{
			$result[] = $block->toArray();
		}

		return $result;
	}

	public function getPayloadText(): string
	{
		$result = '';
		foreach ($this as $block)
		{
			$text = $block->getPayloadText();
			if (!empty($text))
			{
				$result .= $text . PHP_EOL;
			}
		}

		return $this->filterPayloadText(trim($result));
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
}
