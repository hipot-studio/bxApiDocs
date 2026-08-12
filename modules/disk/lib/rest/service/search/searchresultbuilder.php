<?php

declare(strict_types=1);

namespace Bitrix\Disk\Rest\Service\Search;

use Bitrix\Disk\BaseObject;

final class SearchResultBuilder
{
	public function __construct(
		private readonly SearchResultLinkPolicy $linkPolicy,
	)
	{
	}

	/**
	 * @param BaseObject[] $objects
	 */
	public function build(array $objects, callable $externalize): array
	{
		$result = [];
		foreach ($objects as $object)
		{
			$result[] = $this->linkPolicy->apply($object, $externalize($object));
		}

		return $result;
	}
}
