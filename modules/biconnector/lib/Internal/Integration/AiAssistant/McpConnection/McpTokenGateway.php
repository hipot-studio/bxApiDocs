<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection;

use Bitrix\AiAssistant\Service\McpBiTokenService;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto\GatewayResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * The only place in the module that names the aiassistant MCP connection contour.
 *
 * Every call is wrapped: a portal without the contour, or a contour that throws, comes back as the
 * same unsuccessful {@see GatewayResult} the module already knows how to show.
 */
final class McpTokenGateway implements McpTokenGatewayInterface
{
	private const CONTRACT_PROBE_METHOD = 'getForCurrentUser';
	private const ISSUE_FAILED = 'MCP_BI_ISSUE_FAILED';

	private readonly \Closure $moduleLoader;
	private readonly \Closure $contractProbe;
	private readonly \Closure $contourFactory;

	/**
	 * @param callable|null $moduleLoader Answers whether the aiassistant module is loaded.
	 * @param callable|null $contractProbe Answers whether the loaded contour has the awaited contract.
	 * @param callable|null $contourFactory Builds the contour. Nothing but a test passes it: the
	 * contour class is final on the editions the module has to run on, so a double of it can only be
	 * put in from the outside.
	 */
	public function __construct(
		?callable $moduleLoader = null,
		?callable $contractProbe = null,
		?callable $contourFactory = null,
	)
	{
		$this->moduleLoader = $moduleLoader !== null
			? \Closure::fromCallable($moduleLoader)
			: static fn(): bool => Loader::includeModule('aiassistant')
		;
		$this->contractProbe = $contractProbe !== null
			? \Closure::fromCallable($contractProbe)
			: static fn(): bool => method_exists(McpBiTokenService::class, self::CONTRACT_PROBE_METHOD)
		;
		// The contour is built without arguments on purpose: its constructor signature differs
		// between the portals the module has to run on.
		$this->contourFactory = $contourFactory !== null
			? \Closure::fromCallable($contourFactory)
			: static fn(): McpBiTokenService => new McpBiTokenService()
		;
	}

	/**
	 * The order is part of the check: the contract of a module that is not loaded yet cannot be
	 * probed, so probing first would report a healthy portal as unavailable.
	 */
	public function isAvailable(): bool
	{
		if (!($this->moduleLoader)())
		{
			return false;
		}

		return (bool)($this->contractProbe)();
	}

	public function getState(): GatewayResult
	{
		return $this->call(static fn(object $contour): Result => $contour->getForCurrentUser());
	}

	public function issue(): GatewayResult
	{
		return $this->call(static fn(object $contour): Result => $contour->issueForCurrentUser());
	}

	public function reissue(): GatewayResult
	{
		return $this->call(static fn(object $contour): Result => $contour->reissueForCurrentUser());
	}

	/**
	 * The contour is taken as a bare object: {@see McpBiTokenService} is final, so the double a test
	 * puts in through the factory stands in by shape and would not pass a stricter hint.
	 *
	 * @param callable(object): Result $action
	 */
	private function call(callable $action): GatewayResult
	{
		try
		{
			return $this->unpack($action(($this->contourFactory)()));
		}
		catch (\Throwable)
		{
			return GatewayResult::refusal(self::ISSUE_FAILED);
		}
	}

	private function unpack(Result $result): GatewayResult
	{
		if ($result->isSuccess())
		{
			return GatewayResult::success($result->getData());
		}

		$code = (string)($result->getError()?->getCode() ?? '');

		return GatewayResult::refusal($code === '' ? self::ISSUE_FAILED : $code);
	}
}
