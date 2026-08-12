<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

use Bitrix\Landing\AI\SiteBuilder\Dto\TailwindBlockSpecDto;
use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;

class CreateAiSiteTailwindStructure extends Schema
{
	protected function getName(): string
	{
		return 'createAiSiteTailwindStructureSchema';
	}

	protected function getStructure(): SchemaNodeDto
	{
		return new SchemaNodeDto('object', null, [
			'properties' => [
				new SchemaNodeDto('array', 'blocks', [
					'items' => $this->createBlockSpecObject(),
				]),
			],
		]);
	}

	private function createBlockSpecObject(): SchemaNodeDto
	{
		$properties = [];
		foreach (TailwindBlockSpecDto::getRequiredKeys() as $field)
		{
			$properties[] = new SchemaNodeDto($field === 'images_count' ? 'integer' : 'string', $field);
		}

		return new SchemaNodeDto('object', null, [
			'properties' => $properties,
		]);
	}
}
