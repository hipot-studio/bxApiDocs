<?php

namespace Bitrix\BIConnector\Superset\Demo;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorEventLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

class DemoService
{
	private const TOOL_ID = 'crm_bi';
	private const NOT_READY_ERROR_CODE = 'DEMO_NOT_READY';
	private const LOG_METHOD = 'demo.getCredentials';

	/** @var array<int, bool> */
	private static array $availabilityCache = [];

	private IntegratorLogger $logger;

	public function __construct(?IntegratorLogger $logger = null)
	{
		$this->logger = $logger ?? new IntegratorEventLogger();
	}

	public function isAvailable(): bool
	{
		$userId = (int)CurrentUser::get()->getId();

		return self::$availabilityCache[$userId] ??= $this->checkAvailability();
	}

	public static function resetAvailabilityCache(): void
	{
		self::$availabilityCache = [];
	}

	private function checkAvailability(): bool
	{
		if (!Feature::isDemoReportsEnabled())
		{
			return false;
		}

		if (SupersetHostMode::isSelfHosted())
		{
			return false;
		}

		return $this->isToolAvailable() && $this->hasAccessPermission();
	}

	public function getCredentials(string $reportCode): DemoCredentialsResult
	{
		$response = $this->requestCredentials($reportCode, CultureFormatter::getLanguageCode());

		return $this->mapResponse($response);
	}

	protected function requestCredentials(string $reportCode, string $lang): IntegratorResponse
	{
		return IntegratorFactory::getInstance()->getDemoEmbeddedCredentials($reportCode, $lang);
	}

	protected function isToolAvailable(): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return true;
		}

		return ToolsManager::getInstance()->checkAvailabilityByToolId(self::TOOL_ID);
	}

	protected function hasAccessPermission(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS);
	}

	private function mapResponse(IntegratorResponse $response): DemoCredentialsResult
	{
		$status = $response->getStatus();

		if ($status === IntegratorResponse::STATUS_TOO_MANY_REQUESTS)
		{
			return new DemoCredentialsResult(DemoState::RateLimited);
		}

		$credentials = $response->getData();
		if ($status === IntegratorResponse::STATUS_OK && $credentials instanceof Dto\DashboardEmbeddedCredentials)
		{
			return new DemoCredentialsResult(DemoState::Ready, $credentials);
		}

		if ($status === IntegratorResponse::STATUS_NOT_FOUND)
		{
			return new DemoCredentialsResult(
				$this->hasNotReadyError($response) ? DemoState::NotReady : DemoState::Unavailable
			);
		}

		$this->logger->logMethodErrors(self::LOG_METHOD, (string)$status, $response->getErrors());

		return new DemoCredentialsResult(DemoState::Unavailable);
	}

	private function hasNotReadyError(IntegratorResponse $response): bool
	{
		foreach ($response->getErrors() as $error)
		{
			if ($error->getCode() === self::NOT_READY_ERROR_CODE)
			{
				return true;
			}
		}

		return false;
	}
}
