<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class GetMarkupPackageSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD => [
				'type' => ['string', 'integer'],
				'description' => 'Numeric landing AI-site binding identifier of the page context, not the trigger'
					. ' entityId. The server reads the single page of the bound site itself; the page must carry'
					. ' a custom markup block, a page identifier is never passed.',
			],
			'path' => [
				'type' => ['string', 'null'],
				'description' => 'Package file path from the inventory, e.g. index.html or styles.css: returns one part of that file. Omit it to get the inventory of the package without contents.',
			],
			'offset' => [
				'type' => ['integer', 'null'],
				'minimum' => 0,
				'description' => 'Byte offset of the part to read, with path only: 0 or omitted for the first part, then nextOffset of the previous answer until it is null.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return [AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD];
	}
}
