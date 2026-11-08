<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Internal\Digest\Dto\DigestSetupPreset;
use Bitrix\BIConnector\Internal\Digest\Period\SupersetExpressionRecognizer;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestDashboardProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\ScopeValueAccessChecker;

/**
 * Presets of the digest setup read off the report screen. The native filter configuration lives in Superset,
 * so the card of the report is asked for; a report whose card cannot be read transfers nothing.
 * Left open for extension so tests can replace it with a stub.
 */
class DigestSetupScreenPresetReader
{
	private const REMOTE_READ_TIMEOUT = 2;

	public function __construct(private readonly ScopeValueAccessChecker $scopeValueAccessChecker)
	{
	}

	/**
	 * @param array<string, array> $screenState Native filter id => its state, already narrowed by
	 *     {@see DigestSetupDashboardGuard::acceptScreenState()}.
	 */
	public function read(Model\Dashboard $dashboard, array $screenState, int $userId): DigestSetupPreset
	{
		$remoteDashboard = $this->readRemoteDashboard($dashboard);
		if ($remoteDashboard === null)
		{
			return new DigestSetupPreset();
		}

		$dashboardProvider = new DigestDashboardProvider();
		$dashboardProvider->rememberDashboard($dashboard);

		$interpreter = new ScreenStateInterpreter(
			new SupersetExpressionRecognizer(),
			$this->scopeValueAccessChecker,
			$dashboardProvider,
		);

		return $interpreter->interpret(
			new Model\Dashboard($dashboard->getOrmObject(), $remoteDashboard),
			$screenState,
			$userId,
		);
	}

	/**
	 * The screen state is a wish only: without it the wizard opens with the current month. So a slow
	 * instance is not waited for, and a failure of the request is not allowed to move the instance
	 * to ERROR: a click on a menu item must not take the reports of the portal down.
	 */
	private function readRemoteDashboard(Model\Dashboard $dashboard): ?Dto\Dashboard
	{
		$response = IntegratorFactory::getInstance()->getDashboardById(
			(int)$dashboard->getExternalId(),
			self::REMOTE_READ_TIMEOUT,
			arbitrateServiceFailure: false,
		);

		$remoteDashboard = $response->getData();
		if (
			$response->getStatus() !== IntegratorResponse::STATUS_OK
			|| $response->hasErrors()
			|| !$remoteDashboard instanceof Dto\Dashboard
		)
		{
			return null;
		}

		return $remoteDashboard;
	}
}
