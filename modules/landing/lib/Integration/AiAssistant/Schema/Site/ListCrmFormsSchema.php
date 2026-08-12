<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class ListCrmFormsSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'query' => [
				'type' => ['string', 'null'],
				'description' => 'Optional case-insensitive filter by CRM form name or ID.',
			],
		];
	}
}
