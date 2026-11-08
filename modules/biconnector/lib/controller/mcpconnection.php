<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Analytics\AnalyticsManager;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto\ConnectionState;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto\IssueResult;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\McpConnectionService;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\McpTokenGateway;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\RefusalMap;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\ScopeCatalog;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Result;

/**
 * The only server channel of the BI connection popup: read the state, issue, reissue.
 *
 * The actions take no connection parameters - the user is always the current one, the scope is
 * always BI analytics and the lifetime is always the longest the contour issues - and carry no
 * logic of their own: they call the adapter and hand its answer over. Nothing of the answer, the
 * refusal included, is written to a log: the issue answer carries a live secret once.
 */
class McpConnection extends Controller
{
	/**
	 * Reading the state raises no event, so only these two runs reach the funnel.
	 */
	private const ANALYTICS_ACTIONS = [
		AnalyticsManager::MCP_CONNECTION_ISSUE,
		AnalyticsManager::MCP_CONNECTION_REISSUE,
	];

	private const TARIFF_LIMIT_CODE = 'limit_crm_BI_constructor';

	/**
	 * The filters are declared for the whole class on purpose, so the next action of the controller
	 * is closed by default rather than by the memory of its author.
	 *
	 * The order is part of the guard. Every prefilter runs and the refusals pile up in one collection
	 * (@see Controller::triggerOnBeforeAction()), so what the order decides is which refusal comes
	 * first — and the first one is what both processAfterAction() and the popup read. The channel
	 * filter stands before the access gate because both work in every scope: the other way round a
	 * REST call on a portal with an unfit plan would report the plan instead of the channel.
	 *
	 * A prefilters key in configureActions() would replace this set instead of adding to it
	 * (@see Controller::buildFilters()), so per-action prefilters are not used here; a filter for a
	 * single action would be added through the '+prefilters' key.
	 *
	 * The employee filter is left out deliberately: the contour checks that case itself and answers
	 * with its own BI_MCP_NOT_EMPLOYEE branch, which a general access error would hide.
	 */
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new ActionFilter\Scope(ActionFilter\Scope::AJAX),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new BIConstructorAccess(),
		];
	}

	public function getStateAction(): ?ConnectionState
	{
		return $this->takePayload(
			$this->createConnectionService()->getState(),
			McpConnectionService::STATE_KEY,
			ConnectionState::class,
		);
	}

	public function issueAction(): ?IssueResult
	{
		return $this->takePayload(
			$this->createConnectionService()->issue(),
			McpConnectionService::ISSUE_KEY,
			IssueResult::class,
		);
	}

	public function reissueAction(): ?IssueResult
	{
		return $this->takePayload(
			$this->createConnectionService()->reissue(),
			McpConnectionService::ISSUE_KEY,
			IssueResult::class,
		);
	}

	/**
	 * The only point that sees both the refusal of a request filter, which cut the run off before the
	 * body of the action, and the outcome of the action itself.
	 *
	 * The tariff refusal is enriched before the event is raised, so both read the same collection.
	 */
	protected function processAfterAction(Action $action, $result)
	{
		$actionName = mb_strtolower($action->getName());
		if (!in_array($actionName, self::ANALYTICS_ACTIONS, true))
		{
			return null;
		}

		// The channel filter refuses a REST call, yet this hook runs on that pass all the same:
		// without the check every REST probe would land in the funnel.
		if ($this->getScope() !== self::SCOPE_AJAX)
		{
			return null;
		}

		// The values and not the iterator: ErrorCollection inherits the internal array pointer of
		// Dictionary, so after the break below the "first error" would be the replaced one.
		$errors = $this->errorCollection->getValues();
		$this->addLimitCodeToTariffRefusal($errors);

		// The first error by order and not by key: setError() keeps the offset it was given.
		$firstError = reset($errors) ?: null;
		if ($firstError === null)
		{
			$outcome = $result instanceof IssueResult ? $result->outcome : '';
			$this->sendAnalytics($actionName, AnalyticsManager::resolveMcpConnectionOutcomeSlug($outcome), true);

			return null;
		}

		$slug = AnalyticsManager::resolveMcpConnectionRefusalSlug((string)$firstError->getCode());
		$this->sendAnalytics($actionName, $slug, false);

		return null;
	}

	/**
	 * The access gate is shared with the rest of the module and gives out no limit code, so the code
	 * is put in here. The code of the error itself does not change: the other surfaces of the module,
	 * the mobile one included, already read it.
	 *
	 * @param array $errors Snapshot of the error collection, offsets included.
	 */
	private function addLimitCodeToTariffRefusal(array $errors): void
	{
		foreach ($errors as $offset => $error)
		{
			if ($error->getCode() !== BIConstructorAccess::ERROR_INSUFFICIENT_TARIFF)
			{
				continue;
			}

			$this->errorCollection->setError(
				new UserFacingError(
					$error->getMessage(),
					$error->getCode(),
					['limitCode' => self::TARIFF_LIMIT_CODE],
				),
				$offset,
			);

			break;
		}
	}

	/**
	 * The seam of the collector: it sends statically, so a test replaces the call and not the class.
	 */
	protected function sendAnalytics(string $action, string $slug, bool $isSuccess): void
	{
		AnalyticsManager::sendMcpConnectionAnalytics($action, $slug, $isSuccess);
	}

	/**
	 * Built by the defaults of the adapter and replaced in the test. The adapter is not registered
	 * in the service configuration of the module: a service key would be a new public surface, while
	 * this method already makes the controller testable.
	 */
	protected function createConnectionService(): McpConnectionService
	{
		return new McpConnectionService(new McpTokenGateway(), new RefusalMap(), new ScopeCatalog());
	}

	/**
	 * The refusal of the adapter travels into the error collection as it came: its code is the
	 * branch the popup reads.
	 *
	 * A success without the promised payload is a refusal all the same. The channel knows no answer
	 * with neither data nor error, so the broken promise of the adapter leaves the run with the issue
	 * failure branch instead of an empty success the popup would show as nothing.
	 *
	 * @template T of object
	 * @param class-string<T> $expected
	 * @return T|null
	 */
	private function takePayload(Result $result, string $key, string $expected): ?object
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$payload = $result->getData()[$key] ?? null;
		if (!$payload instanceof $expected)
		{
			$this->addError((new RefusalMap())->createError(RefusalMap::BI_MCP_ISSUE_FAILED));

			return null;
		}

		return $payload;
	}
}
