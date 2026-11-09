<?php

namespace Bitrix\BIConnector\Integration\Superset;


use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorInterface;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardRepository;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\Main;

final class SupersetController
{
	public const ERROR_EMPTY_LOGIN_URL = 'EMPTY_LOGIN_URL';

	private IntegratorInterface $integrator;
	private DashboardRepository $dashboardRepository;
	private DashboardGroupRepository $dashboardGroupRepository;

	public function __construct()
	{
		$this->integrator = IntegratorFactory::getInstance();
		$this->initRepositories();
	}

	private function initRepositories(): void
	{
		$this->dashboardRepository = new DashboardRepository($this->integrator);
		$this->dashboardGroupRepository = new DashboardGroupRepository($this->integrator);
	}

	public function getIntegrator(): IntegratorInterface
	{
		return $this->integrator;
	}

	public function setIntegrator(IntegratorInterface $integrator): self
	{
		$this->integrator = $integrator;
		$this->initRepositories();

		return $this;
	}

	public function getDashboardRepository(): DashboardRepository
	{
		return $this->dashboardRepository;
	}

	public function getUnionDashboardGroupRepository(): DashboardGroupRepository
	{
		return $this->dashboardGroupRepository;
	}

	/**
	 * Calls on every BI-constructor page hit and call superset if it's need to up
	 *
	 * @return void
	 */
	public function initializeOrCheckSupersetStatus(): void
	{
		SupersetInitializer::initializeOrCheckSupersetStatus();
	}

	public function isSupersetEnabled(): bool
	{
		return SupersetInitializer::isSupersetReady();
	}

	public function createUser(int $userId): Main\Result
	{
		$result = new Main\Result();

		$user = (new SupersetUserRepository)->getById($userId);
		if (!$user)
		{
			$result->addError(new Main\Error("User with id \"{$userId}\" not found"));

			return $result;
		}

		if ($user->clientId)
		{
			$result->setData([
				'user' => $user,
				'response' => new IntegratorResponse(200),
			]);

			return $result;
		}

		$response = $this->integrator->createUser($user);
		if ($response->hasErrors())
		{
			return $result->addErrors($response->getErrors())->setData(['response' => $response]);
		}

		$data = $response->getData();
		$clientId = $data['client_id'] ?? null;

		if (empty($clientId))
		{
			$result->addError(new Main\Error('Superset proxy returned a success response but without a client_id.'));

			return $result;
		}

		$addResult = SupersetUserTable::addClientId($user->id, $clientId);
		if ($addResult->isSuccess())
		{
			$user->clientId = (string)$clientId;
			$user->updated = true;

			$result->setData([
				'user' => $user,
				'response' => $response,
			]);
		}
		else
		{
			$result->addErrors($addResult->getErrors());
		}

		return $result;
	}

	public function getLoginUrl(): ?string
	{
		$result = $this->getLoginUrlResult();

		return $result->isSuccess() ? $result->getData()['url'] : null;
	}

	public function getLoginUrlResult(): Main\Result
	{
		$result = new Main\Result();

		$response = $this->integrator->getLoginUrl();
		if ($response->hasErrors())
		{
			return $result->addErrors($response->getErrors());
		}

		// An empty 4xx from the proxy carries no errors, so the missing url is the only trace of the failure.
		$data = $response->getData();
		$url = is_array($data) ? ($data['url'] ?? null) : null;
		if (!is_string($url) || $url === '')
		{
			return $result->addError(new Main\Error(
				"Superset integrator returned no login url, response status {$response->getStatus()}.",
				self::ERROR_EMPTY_LOGIN_URL,
			));
		}

		return $result->setData(['url' => $url]);
	}
}