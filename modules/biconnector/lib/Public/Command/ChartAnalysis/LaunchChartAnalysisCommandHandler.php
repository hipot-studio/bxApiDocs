<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Command\ChartAnalysis;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\ChartAnalysis\ChartAnalysisLaunchRateLimiter;
use Bitrix\BIConnector\Internal\ChartAnalysis\ChartAnalysisMessageBuilder;
use Bitrix\BIConnector\Internal\ChartAnalysis\ChartAnalysisPayload;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\AiAgentsChatGate;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoRepository;
use Bitrix\BIConnector\Public\Dto\ChartAnalysisAvailability;
use Bitrix\BIConnector\Public\Provider\ChartAnalysisAvailabilityProvider;
use Bitrix\BIConnector\Public\Provider\SupersetDashboardInfoProvider;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Every check is made before the gate is asked, so a refused launch writes nothing and spends no request
 * of the BitrixGPT limit (AC-010, AC-028).
 */
class LaunchChartAnalysisCommandHandler
{
	private readonly ChartAnalysisAvailabilityProvider $availabilityProvider;
	private readonly \Closure $findDashboard;
	private readonly ChartAnalysisMessageBuilder $messageBuilder;
	private readonly AiAgentsChatGate $chatGate;
	private readonly SupersetDashboardInfoProvider $dashboardInfoProvider;
	private readonly ChartAnalysisLaunchRateLimiter $rateLimiter;

	/**
	 * @param \Closure(int): ?Dashboard|null $findDashboard
	 */
	public function __construct(
		?ChartAnalysisAvailabilityProvider $availabilityProvider = null,
		?\Closure $findDashboard = null,
		?ChartAnalysisMessageBuilder $messageBuilder = null,
		?AiAgentsChatGate $chatGate = null,
		?SupersetDashboardInfoProvider $dashboardInfoProvider = null,
		?ChartAnalysisLaunchRateLimiter $rateLimiter = null,
	)
	{
		$this->availabilityProvider = $availabilityProvider ?? new ChartAnalysisAvailabilityProvider();
		$this->findDashboard = $findDashboard ?? static function(int $dashboardId): ?Dashboard {
			// The repository also fills url parameters, source, scope, tags and groups the launch never reads.
			$ormObject = SupersetDashboardTable::getById($dashboardId)->fetchObject();

			return $ormObject ? new Dashboard($ormObject) : null;
		};
		$this->messageBuilder = $messageBuilder ?? new ChartAnalysisMessageBuilder();
		$this->chatGate = $chatGate ?? new AiAgentsChatGate();
		$this->dashboardInfoProvider = $dashboardInfoProvider ?? new SupersetDashboardInfoProvider(
			ServiceLocator::getInstance()->get(SupersetDashboardInfoRepository::class),
		);
		$this->rateLimiter = $rateLimiter ?? new ChartAnalysisLaunchRateLimiter();
	}

	public function __invoke(LaunchChartAnalysisCommand $command): ChartAnalysisLaunchResult
	{
		$result = new ChartAnalysisLaunchResult();

		$dashboard = $command->dashboardId > 0 ? ($this->findDashboard)($command->dashboardId) : null;
		if ($dashboard === null)
		{
			return $result->refuse(ChartAnalysisLaunchResult::REFUSAL_UNAVAILABLE, 'Report is not found.');
		}

		$availability = $this->availabilityProvider->check($dashboard, $command->userId);
		if ($availability->state === ChartAnalysisAvailability::SUBSCRIPTION_REQUIRED)
		{
			return $result
				->setPromoterCode($availability->promoterCode)
				->refuse(ChartAnalysisLaunchResult::REFUSAL_SUBSCRIPTION_REQUIRED, 'Subscription is required.')
			;
		}

		if ($availability->state !== ChartAnalysisAvailability::READY)
		{
			return $result->refuse(ChartAnalysisLaunchResult::REFUSAL_UNAVAILABLE, 'Chart analysis is unavailable.');
		}

		$payload = ChartAnalysisPayload::fromJson($command->payloadJson);
		if ($payload === null)
		{
			return $result->refuse(ChartAnalysisLaunchResult::REFUSAL_INVALID_PAYLOAD, 'Payload is malformed.');
		}

		$result
			->setVizType($payload->vizType)
			->setPreviousStatus($payload->previousStatus)
		;

		if ($payload->supersetDashboardId !== $dashboard->getExternalId())
		{
			return $result->refuse(
				ChartAnalysisLaunchResult::REFUSAL_FOREIGN_DASHBOARD,
				'Payload belongs to another report.',
			);
		}

		$pageContext = $this->messageBuilder->buildPageContext(
			$payload,
			$dashboard->getId(),
			(string)$dashboard->getOrmObject()->getDetailUrl()->getUri(),
			$this->dashboardInfoProvider->getByDashboardId($dashboard->getId())?->getDescription(),
		);
		if (!$this->messageBuilder->fits($pageContext))
		{
			return $result->refuse(ChartAnalysisLaunchResult::REFUSAL_TOO_LARGE, 'Page context is too large.');
		}

		// The slot is taken before the write, so parallel launches cannot pass the limit, and is given back
		// when nothing was written: a failed write spends no BitrixGPT request.
		$reservation = $this->rateLimiter->reserve($command->userId);
		if ($reservation === null)
		{
			return $result->refuse(ChartAnalysisLaunchResult::REFUSAL_RATE_LIMITED, 'Too many launches.');
		}

		$card = [
			'title' => $this->messageBuilder->buildTitle($payload),
			'prompt' => $this->messageBuilder->buildText($payload),
		];
		try
		{
			$chatResult = $command->chatId > 0
				? $this->chatGate->launchInChat($command->userId, $command->chatId, $card, $pageContext)
				: $this->chatGate->launch($command->userId, $card, $pageContext)
			;
		}
		catch (\Throwable $throwable)
		{
			$this->rateLimiter->release($command->userId, $reservation);

			throw $throwable;
		}

		if (!$chatResult->isSuccess())
		{
			$this->rateLimiter->release($command->userId, $reservation);
			$gateError = $chatResult->getErrors()[0] ?? null;

			return $result->refuse(
				ChartAnalysisLaunchResult::REFUSAL_CHAT_FAILED,
				'Chat was not created.',
				['gateCode' => (string)($gateError?->getCode() ?? '')],
			);
		}

		$chatId = (int)($chatResult->getData()[AiAgentsChatGate::CHAT_ID_KEY] ?? 0);
		if ($chatId <= 0)
		{
			$this->rateLimiter->release($command->userId, $reservation);

			return $result->refuse(ChartAnalysisLaunchResult::REFUSAL_CHAT_FAILED, 'Chat was not created.');
		}

		return $result
			->setChatId($chatId)
			->setBotId((int)($chatResult->getData()[AiAgentsChatGate::BOT_ID_KEY] ?? 0))
			->setContinuation($command->chatId > 0 && $chatId === $command->chatId)
		;
	}
}
