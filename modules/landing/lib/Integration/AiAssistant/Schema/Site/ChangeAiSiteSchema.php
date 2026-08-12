<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class ChangeAiSiteSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'pageId' => [
				'type' => 'integer',
				'description' => 'Target landing page identifier to update.',
			],
			'briefLines' => [
				'type' => 'array',
				'description' => 'Ordered page-change brief as strings.',
				'items' => [
					'type' => 'string',
				],
			],
			'selectedElement' => [
				'type' => 'object',
				'description' => 'Optional selected landing element context.',
				'properties' => [
					'blockId' => [
						'type' => 'integer',
					],
					'selector' => [
						'type' => 'string',
					],
				],
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['pageId', 'briefLines'];
	}
}
