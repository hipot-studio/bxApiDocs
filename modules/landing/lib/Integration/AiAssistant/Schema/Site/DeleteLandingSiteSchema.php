<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class DeleteLandingSiteSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'siteId' => [
				'type' => 'integer',
				'description' => 'Identifier of the site to delete. Must be a positive integer.',
				'minimum' => 1,
			],
			'confirm' => [
				'type' => ['boolean', 'null'],
				'description' => 'Set to true only after the user explicitly confirmed site deletion.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['siteId'];
	}
}
