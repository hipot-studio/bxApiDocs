<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class GetCrmFormFieldsSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'formId' => [
				'type' => ['integer', 'null'],
				'description' => 'CRM web form identifier. Use either formId or the trusted pageId + blockId pair.',
				'minimum' => 1,
			],
			'pageId' => [
				'type' => ['integer', 'null'],
				'description' => 'Trusted landing page identifier that owns the selected CRM-form block.',
				'minimum' => 1,
			],
			'blockId' => [
				'type' => ['integer', 'null'],
				'description' => 'Trusted CRM-form landing block identifier. Must belong to pageId.',
				'minimum' => 1,
			],
		];
	}
}
