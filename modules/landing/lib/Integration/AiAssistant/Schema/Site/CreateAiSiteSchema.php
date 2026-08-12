<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class CreateAiSiteSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'briefLines' => [
				'type' => 'array',
				'description' => 'Ordered site brief as strings.',
				'items' => [
					'type' => 'string',
				],
			],
			AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD => [
				'type' => ['string', 'integer'],
				'description' => 'Landing AI-site binding identifier. It must match entityId passed to BX.AiAssistant.Trigger.execute().',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['briefLines', AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD];
	}
}
