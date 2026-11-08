<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Whether BI Builder answers a REST caller at all, checked before any operation: the BI tool is not
 * switched off by the administrator, the caller has access to BI and the portal is linked to its Superset.
 * MCP decides the same in BiToolBehaviour::isBiAvailable() before a tool is listed; the access to BI
 * is asked of REST alone, as the web and SQL Lab over REST ask it. The link is checked after the access:
 * a caller without access to BI is not told whether the portal is linked.
 */
final class RestAvailability
{
	private const BI_TOOL_ID = 'crm_bi';

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;
	/** @var \Closure(): bool */
	private readonly \Closure $isPortalLinked;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 * @param (\Closure(): bool)|null $isPortalLinked
	 */
	public function __construct(?\Closure $accessControllerFactory = null, ?\Closure $isPortalLinked = null)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
		$this->isPortalLinked = $isPortalLinked
			?? static fn(): bool => DomainLinkService::getInstance()->isLinked()
		;
	}

	public function check(Actor $actor): Result
	{
		if (
			Loader::includeModule('intranet')
			&& !ToolsManager::getInstance()->checkAvailabilityByToolId(self::BI_TOOL_ID)
		)
		{
			return Refusal::error(FailureCode::AccessDenied, 'The BI tool is switched off on this portal.');
		}

		$accessController = ($this->accessControllerFactory)($actor->userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied.');
		}

		if (!($this->isPortalLinked)())
		{
			return Refusal::error(FailureCode::Unavailable, 'BI Builder is not linked to this portal yet.');
		}

		return new Result();
	}
}
