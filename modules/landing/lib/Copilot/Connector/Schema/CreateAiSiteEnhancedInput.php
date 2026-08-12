<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

use Bitrix\Landing\AI\SiteBuilder\Dto\EnhancedInputDto;
use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;

class CreateAiSiteEnhancedInput extends Schema
{
	protected function getName(): string
	{
		return 'createAiSiteEnhancedInputSchema';
	}

	protected function getStructure(): SchemaNodeDto
	{
		$properties = [];
		foreach (EnhancedInputDto::SCHEMA_FIELDS as $field)
		{
			$properties[] = new SchemaNodeDto('string', $field);
		}

		$properties[] = new SchemaNodeDto('array', EnhancedInputDto::DOMAINS_FIELD, [
			'items' => new SchemaNodeDto('string'),
		]);

		return new SchemaNodeDto('object', null, [
			'properties' => $properties,
		]);
	}
}
