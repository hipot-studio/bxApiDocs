<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class PublishLandingSiteSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'siteId' => [
				'type' => 'integer',
				'description' => 'Identifier of the site to publish or unpublish. Must be a positive integer.',
				'minimum' => 1,
			],
			'action' => [
				'type' => 'string',
				'description' => 'Publication action to apply to the site.',
				'enum' => ['publish', 'unpublish'],
			],
			'confirm' => [
				'type' => ['boolean', 'null'],
				'description' => 'Set to true only after the user explicitly confirmed publication status change.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['siteId', 'action'];
	}
}
