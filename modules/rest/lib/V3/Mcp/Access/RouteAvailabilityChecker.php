<?php

namespace Bitrix\Rest\V3\Mcp\Access;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Schema\SchemaManager;

/**
 * The tool map is built once and cached, so the route state it carries may be outdated by the time an agent
 * calls the tool. The regular transport checks the method and its controller on every request
 * (CRestApiServer::processServerExecution()), and the bridge repeats that check against the current schema.
 *
 * The lookups are separate methods so that a test can drive the check without the portal schema.
 */
class RouteAvailabilityChecker
{
	public function __construct(private readonly ?SchemaManager $schemaManager = null)
	{
	}

	/**
	 * @throws McpException when the route is gone, disabled or no longer exported
	 */
	public function assertRouteIsExecutable(MethodDescription $methodDescription): void
	{
		$actualDescription = $this->findMethodDescription($methodDescription->actionUri);
		if ($actualDescription === null || $actualDescription->isMcpExportSuppressed())
		{
			throw $this->routeIsNotAvailable($methodDescription->actionUri);
		}

		$controllerFqcn = $actualDescription->controllerFqcn;
		if ($controllerFqcn !== null && !$this->isControllerEnabled($controllerFqcn))
		{
			throw $this->routeIsNotAvailable($methodDescription->actionUri);
		}
	}

	protected function findMethodDescription(string $actionUri): ?MethodDescription
	{
		return $this->getSchemaManager()->getMethodDescription($actionUri);
	}

	protected function isControllerEnabled(string $controllerFqcn): bool
	{
		return $this->getSchemaManager()->getControllerDataByName($controllerFqcn)?->isEnabled() === true;
	}

	private function getSchemaManager(): SchemaManager
	{
		return $this->schemaManager ?? ServiceLocator::getInstance()->get(SchemaManager::class);
	}

	private function routeIsNotAvailable(string $actionUri): McpException
	{
		return new McpException(sprintf('Method "%s" is not available.', $actionUri));
	}
}
