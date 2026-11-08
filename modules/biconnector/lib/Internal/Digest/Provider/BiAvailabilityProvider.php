<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

/**
 * Local configuration facts a digest run can be refused by. Each one is asked
 * separately: a refusal has to name which of them holds, and none of them may
 * be inferred from an answer of the reports instance.
 *
 * The set repeats what the BI tools check before they run
 * ({@see \Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\BaseBiTool}),
 * minus the module load, which the digest already did inside this module.
 * Left open for extension so unit tests can replace it with a stub.
 */
class BiAvailabilityProvider
{
	private const BI_TOOL_ID = 'crm_bi';

	public function isBiToolsOptionEnabled(): bool
	{
		return Feature::isBitrixGptBiConstructorEnabled();
	}

	public function isBuilderEnabled(): bool
	{
		return Feature::isBuilderEnabled();
	}

	/**
	 * A portal without intranet has no tool list to check against, so the tool
	 * counts as available there.
	 */
	public function isBiToolAvailable(): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return true;
		}

		return ToolsManager::getInstance()->checkAvailabilityByToolId(self::BI_TOOL_ID);
	}

	public function isDomainLinked(): bool
	{
		return DomainLinkService::getInstance()->isLinked();
	}
}
