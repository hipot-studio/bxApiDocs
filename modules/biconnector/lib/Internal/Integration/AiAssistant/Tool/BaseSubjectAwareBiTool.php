<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\SubjectAwareToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Request\Dto\RequestSubjectDto;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\ImpactAwareTool;

/**
 * Base of the BI tools that read the per-request signed subject. Shares its
 * behaviour with {@see BaseBiTool} through {@see BiToolBehaviour} and differs
 * only in the contract it extends.
 *
 * Tools below stay written against a single set of arguments: the pinned values
 * are applied here, before any tool sees them.
 */
abstract class BaseSubjectAwareBiTool extends SubjectAwareToolContract implements ImpactAwareTool
{
	use BiToolBehaviour;

	/**
	 * Enforces the per-user BI-tools rate limit and lets the pinned context win
	 * over the arguments the model sent, then delegates to the base runner.
	 *
	 * The rate limit is the same window on the same user as in
	 * {@see BaseBiTool::run()}: only the interception has to be repeated, because
	 * the two contracts declare `run()` with incompatible signatures, while the
	 * check itself lives in {@see BiToolBehaviour::enforceRateLimit()}.
	 *
	 * @inheritDoc
	 * @throws McpException When the per-user rate limit is exceeded or the pinned context is unreadable.
	 */
	public function run(int $userId, ?RequestSubjectDto $subject, array $args = []): array
	{
		$this->callerUserId = $userId;
		$this->enforceRateLimit($userId);

		return parent::run($userId, $subject, (new PinnedArgumentResolver())->apply($this, $subject, $args));
	}

	public function canList(int $userId, ?RequestSubjectDto $subject): bool
	{
		return self::isBiAvailable();
	}

	public function canRun(int $userId, ?RequestSubjectDto $subject): bool
	{
		if (!self::isBiAvailable())
		{
			return false;
		}

		SupersetInitializer::initializeOrCheckSupersetStatus();

		return true;
	}
}
