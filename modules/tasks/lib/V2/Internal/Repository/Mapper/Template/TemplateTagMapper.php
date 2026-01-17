<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\Tag;
use Bitrix\Tasks\V2\Internal\Entity\Template\TagCollection;

class TemplateTagMapper
{
	public function mapToEntity(array $tag): Tag
	{
		return Tag::mapFromArray([
			'id' => $tag['ID'] ?? null,
			'templateId' => $tag['TEMPLATE_ID'] ?? null,
			'name' => $tag['NAME'] ?? null,
			'ownerId' => $tag['USER_ID'] ?? null,
		]);
	}

	public function mapToCollection(array $tags): TagCollection
	{
		$collection = new TagCollection();
		foreach ($tags as $tag)
		{
			$collection->add($this->mapToEntity($tag));
		}

		return $collection;
	}
}
