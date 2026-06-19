<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity;

use Bitrix\Im\V2\Message\Builder\Entity\Blocks\AbstractBlock;
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

		return trim($result);
	}
}
