<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class RestoreLandingSiteSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'siteId' => [
				'type' => 'integer',
				'description' => 'Identifier of the deleted site to restore. Must be a positive integer.',
				'minimum' => 1,
			],
			'confirm' => [
				'type' => 'boolean',
				'description' => 'Set to true only after the user explicitly confirmed site restoration.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['siteId', 'confirm'];
	}
}
