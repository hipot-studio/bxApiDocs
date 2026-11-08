<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\AgentOperation;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\AgentOperationCatalog;
use Bitrix\Main\Loader;

/**
 * Reading surface of the BI agent operation catalogue for whoever shows the operations to the
 * person granting the connection.
 *
 * The enumerating part of a tool set description is assembled from the same catalogue by
 * {@see \Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\AgentOperationsDescription},
 * so the text of the popup and the text of the instruction cannot diverge.
 */
final class AgentOperationsProvider
{
	private ?AgentOperationCatalog $catalog = null;

	/**
	 * @return array<string, AgentOperation[]> Records of every set, keyed by tool set code; empty
	 * when the module declaring the tools is unavailable and there is nothing to describe.
	 */
	public function getAllOperations(): array
	{
		if (!$this->isCatalogAvailable())
		{
			return [];
		}

		return $this->getCatalog()->getAllOperations();
	}

	private function getCatalog(): AgentOperationCatalog
	{
		return $this->catalog ??= new AgentOperationCatalog();
	}

	/**
	 * The catalogue reads name and impact off the tool classes, which the module of the agent
	 * declares; the provider is public, so it cannot assume that module is already included.
	 */
	private function isCatalogAvailable(): bool
	{
		return Loader::includeModule('aiassistant');
	}
}
