<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;

class CreateAiSiteTailwindBlockHtml extends Schema
{
	protected function getName(): string
	{
		return 'createAiSiteTailwindBlockHtmlSchema';
	}

	protected function getStructure(): SchemaNodeDto
	{
		return new SchemaNodeDto('object', null, [
			'properties' => [
				new SchemaNodeDto('string', 'html'),
			],
		]);
	}
}
