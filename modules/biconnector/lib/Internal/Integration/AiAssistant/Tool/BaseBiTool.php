<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\ImpactAwareTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\RateLimit\BiToolsRateLimiter;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\DestructiveBudget;
use Bitrix\Main\DI\ServiceLocator;

abstract class BaseBiTool extends ToolContract implements ImpactAwareTool
{
	use BiToolBehaviour;

	/**
	 * Enforces the per-user BI-tools rate limit before delegating to the base
	 * runner. The check itself is shared with {@see BaseSubjectAwareBiTool::run()}
	 * through {@see BiToolBehaviour::enforceRateLimit()}, so both branches count
	 * into one budget.
	 *
	 * @inheritDoc
	 * @throws McpException When the per-user rate limit is exceeded.
	 */
	public function run(int $userId, ...$args): array
	{
		$this->callerUserId = $userId;
		$this->enforceRateLimit($userId);

		return parent::run($userId, ...$args);
	}

	/**
	 * Charges an irreversible operation to the per-user destructive budget, which
	 * counts distinct objects rather than calls: retrying the same object is free,
	 * a sweep across many objects is not.
	 *
	 * Stays for good: UnpublishDashboardTool never moves onto a builder operation.
	 *
	 * @param string $objectType `dashboard`, `chart` or `dataset`.
	 * @throws McpException When the destructive budget is spent.
	 */
	protected function spendDestructiveBudget(int $userId, string $objectType, int $objectId): void
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			$this->legacySpendDestructiveBudget($userId, $objectType, $objectId);

			return;
		}

		// Resolved per call, not injected: aiassistant keeps a tool as a process-wide singleton,
		// while the limiter behind the service code may be replaced (tests do it per case).
		$budget = DestructiveBudget::forCurrentLimiter();
		$result = $budget->spend(new Actor($userId, Channel::Mcp), $objectType, $objectId);
		if (!$result->isSuccess())
		{
			throw new McpException($result->getError()->getMessage());
		}
	}

	private function legacySpendDestructiveBudget(int $userId, string $objectType, int $objectId): void
	{
		$limiter = ServiceLocator::getInstance()->get('biconnector.service.biToolsRateLimiter');

		$limit = $limiter->consumeObject($userId, $objectType . ':' . $objectId);
		if (!$limit['allowed'])
		{
			throw new McpException(
				'Destructive limit exceeded: at most ' . BiToolsRateLimiter::DESTRUCTIVE_MAX_OBJECTS
				. ' irreversible operations on distinct objects per minute. Retry in '
				. $limit['retryAfter'] . ' seconds.'
			);
		}
	}

	public function canList(int $userId): bool
	{
		return self::isBiAvailable();
	}

	public function canRun(int $userId): bool
	{
		if (!self::isBiAvailable())
		{
			return false;
		}

		SupersetInitializer::initializeOrCheckSupersetStatus();

		return true;
	}
}
