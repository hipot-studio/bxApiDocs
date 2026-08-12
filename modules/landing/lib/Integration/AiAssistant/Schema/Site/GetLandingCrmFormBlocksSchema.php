<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class GetLandingCrmFormBlocksSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'pageId' => [
				'type' => 'integer',
				'description' => 'Landing page identifier to inspect for CRM-form blocks. Must be a positive integer.',
				'minimum' => 1,
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['pageId'];
	}
}
