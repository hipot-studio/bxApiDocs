<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class SetLandingCrmFormSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'pageId' => [
				'type' => 'integer',
				'description' => 'Landing page identifier that owns the target CRM-form block. Must be a positive integer.',
				'minimum' => 1,
			],
			'blockId' => [
				'type' => 'integer',
				'description' => 'CRM-form landing block identifier to update. Must belong to pageId.',
				'minimum' => 1,
			],
			'formId' => [
				'type' => 'integer',
				'description' => 'CRM web form identifier to set into the target block. Must be readable by the current user.',
				'minimum' => 1,
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['pageId', 'blockId', 'formId'];
	}
}
