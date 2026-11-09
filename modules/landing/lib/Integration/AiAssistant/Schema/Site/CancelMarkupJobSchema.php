<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class CancelMarkupJobSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'jobId' => [
				'type' => 'string',
				'description' => 'Job identifier returned by submit_markup_package.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['jobId'];
	}
}
