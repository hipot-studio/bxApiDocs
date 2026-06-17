<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\SubTemplate;
use Bitrix\Tasks\V2\Internal\Entity\Template\SubTemplateCollection;

class SubTemplateMapper
{
	public function mapToEntity(array $data): ?SubTemplate
	{
		return SubTemplate::mapFromArray([
			'templateId' => $data['TEMPLATE_ID'] ?? null,
			'parentTemplateId' => $data['PARENT_TEMPLATE_ID'] ?? null,
			'direct' => $data['DIRECT'] ?? null,
		]);
	}

	public function mapToCollection(array $data): SubTemplateCollection
	{
		$entities = new SubTemplateCollection();

		foreach ($data as $item)
		{
			$entities->add($this->mapToEntity($item));
		}

		return $entities;
	}
}
