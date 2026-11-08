<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto\ConnectionState;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto\GatewayResult;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto\IssueResult;
use Bitrix\Main\Result;

/**
 * Facade of the adapter: the three operations of the popup over the contour port.
 *
 * Nothing is kept between calls - neither the state, nor the availability probe, nor the decoded
 * scope - and no operation writes the secret anywhere: it leaves only inside the command line of
 * the very answer that issued it.
 */
final class McpConnectionService
{
	public const STATE_KEY = 'state';
	public const ISSUE_KEY = 'issue';

	private readonly \Closure $commandBuilder;

	/**
	 * @param callable|null $commandBuilder Builds the command line out of an issue answer and its
	 * secret. Taken as a callable because {@see CommandBuilder} is final and builds statically,
	 * while every collaborator here has to stay replaceable in a test.
	 */
	public function __construct(
		private readonly McpTokenGatewayInterface $gateway,
		private readonly RefusalMap $refusalMap,
		private readonly ScopeCatalog $scopeCatalog,
		?callable $commandBuilder = null,
	)
	{
		$this->commandBuilder = $commandBuilder !== null
			? \Closure::fromCallable($commandBuilder)
			: static fn(GatewayResult $issued, string $token): ?string => CommandBuilder::build($issued, $token)
		;
	}

	/**
	 * @return Result {@see ConnectionState} under {@see self::STATE_KEY}, or a refusal of the module.
	 */
	public function getState(): Result
	{
		$result = new Result();
		if (!$this->isBlockAvailable())
		{
			return $result->addError($this->refusalMap->createError(RefusalMap::BI_MCP_UNAVAILABLE));
		}

		$state = $this->gateway->getState();
		if (!$state->isSuccess())
		{
			return $result->addError($this->refusalMap->createError($this->resolveBranch($state)));
		}

		return $result->setData([
			self::STATE_KEY => new ConnectionState(
				$state->hasConnection(),
				$state->getConnectionTitle(),
				$this->scopeCatalog->getNames($state->getConnectionScopes()),
				$state->getConnectionCreatedAt(),
				$state->getConnectionExpiresAt(),
			),
		]);
	}

	/**
	 * @return Result {@see IssueResult} under {@see self::ISSUE_KEY}, or a refusal of the module.
	 */
	public function issue(): Result
	{
		return $this->createConnection(
			fn(): GatewayResult => $this->gateway->issue(),
			fn(string $branch): McpConnectionRefusalError => $this->refusalMap->createError($branch),
		);
	}

	/**
	 * @return Result {@see IssueResult} under {@see self::ISSUE_KEY}, or a refusal of the module.
	 */
	public function reissue(): Result
	{
		return $this->createConnection(
			fn(): GatewayResult => $this->gateway->reissue(),
			fn(string $branch): McpConnectionRefusalError => $this->refusalMap->createReissueError($branch),
		);
	}

	/**
	 * @param callable(): GatewayResult $port
	 * @param callable(string): McpConnectionRefusalError $refusal
	 */
	private function createConnection(callable $port, callable $refusal): Result
	{
		$result = new Result();
		if (!$this->isBlockAvailable())
		{
			return $result->addError($refusal(RefusalMap::BI_MCP_UNAVAILABLE));
		}

		// The state is asked first only to learn the address, so a contour that refuses anyway
		// answers with its own branch: the address is not the reason to show while the refusal is.
		// The read happens before any mutation, so the refusal carries no repeat marks even in a
		// reissue: nothing has been issued or replaced yet.
		$state = $this->gateway->getState();
		if (!$state->isSuccess())
		{
			return $result->addError($this->refusalMap->createError($this->resolveBranch($state)));
		}

		if (!$this->hasServerUrl($state))
		{
			return $result->addError($refusal(RefusalMap::BI_MCP_SERVER_URL_MISSING));
		}

		$issued = $port();
		if (!$issued->isSuccess())
		{
			return $result->addError($refusal($this->resolveBranch($issued)));
		}

		if ($issued->getOutcome() === IssueResult::OUTCOME_EXISTS)
		{
			return $result->setData([self::ISSUE_KEY => $this->createIssueResult($issued, null)]);
		}

		$command = ($this->commandBuilder)($issued, (string)$issued->getToken());
		if ($command === null)
		{
			// The address was there before the call and is gone from its answer: the connection is
			// already issued or replaced, so this is a failed issue and not the untouched address branch.
			return $result->addError($refusal(RefusalMap::BI_MCP_ISSUE_FAILED));
		}

		return $result->setData([self::ISSUE_KEY => $this->createIssueResult($issued, $command)]);
	}

	/**
	 * The scope of the answer is the one the contour issues with, and the issue answer does not
	 * carry it: only the state answer does, and only while a connection is alive.
	 */
	private function createIssueResult(GatewayResult $issued, ?string $command): IssueResult
	{
		return new IssueResult(
			(string)$issued->getOutcome(),
			(string)$issued->getExpiresAt(),
			$command,
			$this->scopeCatalog->getNames([ScopeCatalog::BI_SCOPE]),
		);
	}

	/**
	 * A feature turned off and a contour that is not there reach the user as the same refusal: the
	 * popup has nothing to say about the difference.
	 */
	private function isBlockAvailable(): bool
	{
		return Feature::isMcpBiConnectionEnabled() && $this->gateway->isAvailable();
	}

	private function resolveBranch(GatewayResult $refused): string
	{
		return $this->refusalMap->resolveBranch((string)$refused->getRefusalCode());
	}

	private function hasServerUrl(GatewayResult $state): bool
	{
		$url = $state->getServerUrl();

		return $url !== null && trim($url) !== '';
	}
}
