<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Analytics\AnalyticsManager;
use Bitrix\BIConnector\Public\Command\ChartAnalysis\ChartAnalysisLaunchResult;
use Bitrix\BIConnector\Public\Command\ChartAnalysis\LaunchChartAnalysisCommand;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Starts a BitrixGPT chat about one chart of a report. The page shows only the phrases of this controller:
 * the technical text of a refusal goes to the log.
 */
class ChartAnalysis extends Controller
{
	public const ERROR_UNAVAILABLE = 'CHART_ANALYSIS_UNAVAILABLE';
	public const ERROR_SUBSCRIPTION_REQUIRED = 'CHART_ANALYSIS_SUBSCRIPTION_REQUIRED';
	public const ERROR_INVALID_PAYLOAD = 'CHART_ANALYSIS_INVALID_PAYLOAD';
	public const ERROR_TOO_LARGE = 'CHART_ANALYSIS_TOO_LARGE';
	public const ERROR_RATE_LIMITED = 'CHART_ANALYSIS_RATE_LIMITED';
	public const ERROR_CHAT_FAILED = 'CHART_ANALYSIS_CHAT_FAILED';

	private const LAUNCH_ACTION = 'launch';

	/** A foreign dashboard is told apart only in analytics: the page gets the same answer as for broken data. */
	private const ERROR_CODES = [
		ChartAnalysisLaunchResult::REFUSAL_UNAVAILABLE => self::ERROR_UNAVAILABLE,
		ChartAnalysisLaunchResult::REFUSAL_SUBSCRIPTION_REQUIRED => self::ERROR_SUBSCRIPTION_REQUIRED,
		ChartAnalysisLaunchResult::REFUSAL_INVALID_PAYLOAD => self::ERROR_INVALID_PAYLOAD,
		ChartAnalysisLaunchResult::REFUSAL_FOREIGN_DASHBOARD => self::ERROR_INVALID_PAYLOAD,
		ChartAnalysisLaunchResult::REFUSAL_TOO_LARGE => self::ERROR_TOO_LARGE,
		ChartAnalysisLaunchResult::REFUSAL_RATE_LIMITED => self::ERROR_RATE_LIMITED,
		ChartAnalysisLaunchResult::REFUSAL_CHAT_FAILED => self::ERROR_CHAT_FAILED,
	];

	private ?ChartAnalysisLaunchResult $launchResult = null;

	/**
	 * Scope must precede the access filters, so that a REST call is refused before anything else runs.
	 * The report page opens by the view right on the report without the right on the section, so
	 * BIConstructorAccess is not applied: the command checks the tariff, the tool and the view right.
	 */
	protected function getDefaultPreFilters(): array
	{
		$filters = [
			...parent::getDefaultPreFilters(),
			new ActionFilter\Scope(ActionFilter\Scope::AJAX),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
		];

		if (Loader::includeModule('intranet'))
		{
			$filters[] = new IntranetUser();
		}

		return $filters;
	}

	/**
	 * @param int $dashboardId Id of the report in Bitrix24, not in Superset.
	 * @param string $payload The bxChartAnalyze message as the iframe sent it.
	 * @param int $chatId Chat of an earlier analysis on the page; the answer names the chat actually used.
	 *
	 * @return array{chatId: int, botId: int}|null The reply of the bot is told apart from other messages by botId.
	 */
	public function launchAction(int $dashboardId, string $payload, int $chatId = 0): ?array
	{
		$result = $this->launchSafely(
			$dashboardId,
			$payload,
			(int)$this->getCurrentUser()?->getId(),
			max(0, $chatId),
		);
		$this->launchResult = $result;

		if ($result->isSuccess() && $result->getChatId() > 0)
		{
			return [
				'chatId' => $result->getChatId(),
				'botId' => $result->getBotId(),
			];
		}

		$code = self::ERROR_CODES[$result->getRefusal()] ?? self::ERROR_CHAT_FAILED;
		if ($code === self::ERROR_CHAT_FAILED)
		{
			$this->logFailure($result->getErrors(), $result->getRefusal());
		}

		$this->addError($this->createUserFacingError($code, $result));

		return null;
	}

	protected function processAfterAction(Action $action, $result)
	{
		if (mb_strtolower($action->getName()) !== self::LAUNCH_ACTION)
		{
			return null;
		}

		// The hook also runs for REST requests rejected by the scope filter.
		if ($this->getScope() !== self::SCOPE_AJAX)
		{
			return null;
		}

		$isSuccess = $this->errorCollection->isEmpty();
		$launchResult = $this->launchResult;

		$this->sendAnalytics(
			$launchResult?->getVizType() ?? '',
			$isSuccess ? '' : ($launchResult?->getRefusal() ?? ''),
			$launchResult?->getPreviousStatus(),
			$isSuccess,
			$launchResult?->isContinuation() ?? false,
		);

		return null;
	}

	protected function launch(
		int $dashboardId,
		string $payload,
		int $userId,
		int $chatId = 0,
	): ChartAnalysisLaunchResult
	{
		$result = (new LaunchChartAnalysisCommand($dashboardId, $payload, $userId, $chatId))->run();
		if ($result instanceof ChartAnalysisLaunchResult)
		{
			return $result;
		}

		return (new ChartAnalysisLaunchResult())->refuse(
			ChartAnalysisLaunchResult::REFUSAL_CHAT_FAILED,
			'The launch command answered with a result of an unexpected class.',
		);
	}

	protected function sendAnalytics(
		string $vizType,
		string $refusal,
		?string $previousStatus,
		bool $isSuccess,
		bool $isContinuation = false,
	): void
	{
		AnalyticsManager::sendChartAnalysisLaunchAnalytics(
			$vizType,
			$refusal,
			$previousStatus,
			$isSuccess,
			$isContinuation,
		);
	}

	/**
	 * @param Error[] $errors
	 */
	protected function logFailure(array $errors, string $refusal): void
	{
		Logger::logErrors($errors, ['action' => 'chartAnalysis.launch', 'refusal' => $refusal]);
	}

	private function launchSafely(
		int $dashboardId,
		string $payload,
		int $userId,
		int $chatId,
	): ChartAnalysisLaunchResult
	{
		try
		{
			return $this->launch($dashboardId, $payload, $userId, $chatId);
		}
		catch (\Throwable $exception)
		{
			return (new ChartAnalysisLaunchResult())->refuse(
				ChartAnalysisLaunchResult::REFUSAL_CHAT_FAILED,
				$exception->getMessage(),
			);
		}
	}

	private function createUserFacingError(string $code, ChartAnalysisLaunchResult $result): UserFacingError
	{
		$customData = null;
		if ($code === self::ERROR_SUBSCRIPTION_REQUIRED && $result->getPromoterCode() !== null)
		{
			$customData = ['promoterCode' => $result->getPromoterCode()];
		}

		return new UserFacingError($this->getErrorMessage($code), $code, $customData);
	}

	private function getErrorMessage(string $code): string
	{
		return (string)match ($code)
		{
			self::ERROR_UNAVAILABLE => Loc::getMessage('BICONNECTOR_CONTROLLER_CHART_ANALYSIS_ERROR_UNAVAILABLE'),
			self::ERROR_SUBSCRIPTION_REQUIRED => Loc::getMessage(
				'BICONNECTOR_CONTROLLER_CHART_ANALYSIS_ERROR_SUBSCRIPTION_REQUIRED',
			),
			self::ERROR_INVALID_PAYLOAD => Loc::getMessage('BICONNECTOR_CONTROLLER_CHART_ANALYSIS_ERROR_INVALID_PAYLOAD'),
			self::ERROR_TOO_LARGE => Loc::getMessage('BICONNECTOR_CONTROLLER_CHART_ANALYSIS_ERROR_TOO_LARGE'),
			self::ERROR_RATE_LIMITED => Loc::getMessage('BICONNECTOR_CONTROLLER_CHART_ANALYSIS_ERROR_RATE_LIMITED'),
			default => Loc::getMessage('BICONNECTOR_CONTROLLER_CHART_ANALYSIS_ERROR_CHAT_FAILED'),
		};
	}
}
