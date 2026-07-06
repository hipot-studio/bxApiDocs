<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Sources;

use Bitrix\Im\V2\Registry;

/**
 * @implements \IteratorAggregate<int,Source>
 * @method Source offsetGet($key)
 */
class SourceCollection extends Registry implements \JsonSerializable
{
	public function jsonSerialize(): ?array
	{
		$result = [];
		foreach ($this as $source)
		{
			$result[$source->getId()] = $source->jsonSerialize();
		}

		if (empty($result))
		{
			return null;
		}

		return $result;
	}

	public function toArray(): ?array
	{
		$result = [];
		foreach ($this as $source)
		{
			$result[$source->getId()] = $source->toArray();
		}

		if (empty($result))
		{
			return null;
		}

		return $result;
	}

	public static function create($sources): self
	{
		$collection = new self();

		foreach ($sources as $id => $source)
		{
			$source['id'] = $id;
			$collection->append(Source::create($source));
		}

		return $collection;
	}
}
