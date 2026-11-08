<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Public\Service\NodeService;
use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Internal\Exception\OrgStructureUnavailableException;

final class CompatOrgStructureGateway extends OrgStructureGateway
{
	private ?CompatNodeService $compatNodeService = null;

	/**
	 * @throws OrgStructureUnavailableException
	 */
	protected function getNodeService(): NodeService
	{
		if (!Loader::includeModule('humanresources'))
		{
			throw OrgStructureUnavailableException::moduleUnavailable();
		}

		return $this->compatNodeService ??= new CompatNodeService();
	}
}
