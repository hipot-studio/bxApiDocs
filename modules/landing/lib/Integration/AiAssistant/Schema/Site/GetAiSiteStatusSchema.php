<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class GetAiSiteStatusSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'jobId' => [
				'type' => 'string',
				'description' => 'Job identifier returned by create_ai_site.',
			],
			'seconds' => [
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 60,
				'description' => 'Optional delay in seconds before checking the current generation status. Defaults to 0 and is capped at 60.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['jobId'];
	}
}
