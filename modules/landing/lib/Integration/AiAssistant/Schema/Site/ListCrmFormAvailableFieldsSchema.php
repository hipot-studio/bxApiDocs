<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class ListCrmFormAvailableFieldsSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'formId' => [
				'type' => 'integer',
				'description' => 'CRM web form identifier used to resolve the current CRM form document scheme and field catalog.',
				'minimum' => 1,
			],
			'query' => [
				'type' => ['string', 'null'],
				'description' => 'Optional case-insensitive filter by field name, caption, entity, or type.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['formId'];
	}
}
