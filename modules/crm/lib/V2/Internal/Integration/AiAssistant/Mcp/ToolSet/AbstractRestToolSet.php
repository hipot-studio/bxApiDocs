<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\RestV3McpTools;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access\CrmMethodAccessChecker;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Mcp\Access\MethodAccessCheckerInterface;
use Bitrix\Rest\V3\Mcp\ToolSet\RestToolSetProvider;

Loader::requireModule('aiassistant');
Loader::requireModule('rest');

abstract class AbstractRestToolSet extends RestToolSetProvider
{
	private const ENTITY_TYPE_QUERY_PARAM = 'entityTypeId';

	public function canList(int $userId): bool
	{
		return $this->isAvailableFor($userId);
	}

	public function canRun(int $userId): bool
	{
		return $this->isAvailableFor($userId);
	}

	public function getRegistryToolNames(): array
	{
		if (!$this->isPublicationEnabled())
		{
			return [];
		}

		return parent::getRegistryToolNames();
	}

	public function isVisibleInInternalIndex(): bool
	{
		return $this->isPublicationEnabled();
	}

	protected function getOwnerModuleId(): string
	{
		return 'crm';
	}

	protected function getInheritedEntityQueryParams(): array
	{
		return [self::ENTITY_TYPE_QUERY_PARAM];
	}

	protected function getAccessChecker(): MethodAccessCheckerInterface
	{
		return new CrmMethodAccessChecker();
	}

	private function isAvailableFor(int $userId): bool
	{
		return $userId > 0 && $this->isPublicationEnabled();
	}

	private function isPublicationEnabled(): bool
	{
		return Feature::enabled(RestV3McpTools::class);
	}
}
