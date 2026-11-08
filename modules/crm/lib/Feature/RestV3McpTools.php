<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\RestV3;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\PublicationCache;
use Bitrix\Main\Localization\Loc;

final class RestV3McpTools extends AbstractCrmFlag
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_REST_V3_MCP_TOOLS_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return RestV3::getInstance();
	}

	public function enabledByDefault(): bool
	{
		return false;
	}

	protected function onEnable(): void
	{
		parent::onEnable();

		PublicationCache::clear();
	}

	protected function onDisable(): void
	{
		parent::onDisable();

		PublicationCache::clear();
	}
}
