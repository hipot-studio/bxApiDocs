<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class SearchLandingSitesSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'query' => [
				'type' => 'string',
				'description' => 'Search query used to find sites by title or site code. Must be a non-empty string.',
				'minLength' => 1,
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['query'];
	}
}
