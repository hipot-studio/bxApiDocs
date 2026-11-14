<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use InvalidArgumentException;

final readonly class CatalogMetadata
{
	/** @var list<string> */
	public array $tags;

	/**
	 * @param list<string> $tags
	 */
	public function __construct(
		public ?int $legalEntityId = null,
		public ?string $purpose = null,
		array $tags = [],
		public ?int $responsibleId = null,
	)
	{
		foreach ([$legalEntityId, $responsibleId] as $id)
		{
			if ($id !== null && ($id <= 0 || $id > 2147483647))
			{
				throw new InvalidArgumentException('Invalid catalog metadata identity.');
			}
		}

		if (!array_is_list($tags))
		{
			throw new InvalidArgumentException('Invalid catalog metadata tags.');
		}

		foreach ($tags as $tag)
		{
			if (!is_string($tag) || trim($tag) === '')
			{
				throw new InvalidArgumentException('Invalid catalog metadata tags.');
			}
		}

		$this->tags = $tags;
	}
}
