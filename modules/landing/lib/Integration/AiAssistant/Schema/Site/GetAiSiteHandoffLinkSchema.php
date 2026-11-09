<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;
use Bitrix\Landing\Integration\AiAssistant\Service\AiPromptHandoffService;

class GetAiSiteHandoffLinkSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'target' => [
				'type' => 'string',
				'enum' => [AiPromptHandoffService::TARGET_SITES_AI, AiPromptHandoffService::TARGET_EDITOR],
				'description' => 'Where the link leads: "sites_ai" - the AI site creation page, where the request'
					. ' starts a new AI site in a new chat; "editor" - the editor of an AI site, where the request'
					. ' goes to the chat of that site.',
			],
			'request' => [
				'type' => 'string',
				'minLength' => 1,
				'maxLength' => AiPromptHandoffService::MAX_PROMPT_LENGTH,
				'description' => 'The request of the user to carry, in the words of the user. It is sent to the'
					. ' chat automatically when the user opens the link.',
			],
			'siteId' => [
				'type' => 'integer',
				'minimum' => 1,
				'description' => 'Site id from search_landing_sites. Required for target "editor", ignored for'
					. ' "sites_ai".',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['target', 'request'];
	}
}
