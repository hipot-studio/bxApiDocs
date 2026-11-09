<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class GetMarkupJobStatusSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'jobId' => [
				'type' => 'string',
				'description' => 'Job identifier returned by submit_markup_package.',
			],
			'wait' => [
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 20,
				'description' => 'Optional wait window in seconds: the tool re-reads the job state in short intervals and returns early on a final status. Defaults to 0 and is capped at 20.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['jobId'];
	}
}
