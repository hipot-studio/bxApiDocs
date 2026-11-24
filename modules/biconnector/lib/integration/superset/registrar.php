<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Superset\Config\ConfigContainer;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class Registrar
{
	public const ERROR_GENERATION_CONFIRMATION_REQUIRED = 'REBIND_GENERATION_CONFIRMATION_REQUIRED';

	private const REGISTER_STAGE_OPTION = '~superset_register_stage';

	private static self $instance;

	private function __construct(private readonly ConfigContainer $config)
	{}

	public static function getRegistrar(): self
	{
		if (!isset(self::$instance))
		{
			self::$instance = new self(ConfigContainer::getConfigContainer());
		}

		return self::$instance;
	}

	public function clear(string $reason = ''): void
	{
		$this->setRegisterStage(0);
		$this->config->clearConfig($reason);
		DomainLinkService::getInstance()->clearUnlinkedStatus();
	}

	public function isComplete(): bool
	{
		return $this->config->isPortalIdBorrowed() || $this->getRegisterStage() >= 2;
	}

	public function register(): Result
	{
		while ($this->getRegisterStage() < 2)
		{
			$nextResult = $this->next();
			if (!$nextResult->isSuccess())
			{
				return $nextResult;
			}
		}

		return new Result();
	}

	public function rebind(bool $isGenerationChangeConfirmed = false): Result
	{
		$response = IntegratorFactory::getInstance()->registerPortal();

		$responseData = $response->getData();
		$isRebindAnswer = !empty($responseData['rebind']);

		if (!$isRebindAnswer && $response->hasErrors())
		{
			SupersetInitializerLogger::logErrors(
				[new Error('Rebind register request end with errors'), ...$response->getErrors()],
				['STATUS_CODE' => $response->getStatus()]
			);

			return (new Result())
				->addErrors($response->getErrors())
				->setData(['STATUS_CODE' => $response->getStatus()])
			;
		}

		// A registered answer without the rebind flag binds the portal to the same server as the rebind answer does,
		// so both are allowed only for the generation of the local data or after the explicit confirmation.
		$targetGeneration = (string)($responseData['generation'] ?? '');
		if (!$isGenerationChangeConfirmed && !$this->holdsLocalDataGeneration($targetGeneration))
		{
			return $this->refuseUnconfirmedRebind($targetGeneration);
		}

		if ($isRebindAnswer)
		{
			$portalId = $responseData['portalId'] ?? null;
			if (empty($portalId))
			{
				return new Result();
			}

			$this->config->setPortalId($portalId);
			$this->config->setPortalIdVerified(true);
			$this->setRegisterStage(2);
		}

		if (!$this->holdsServerGeneration($targetGeneration))
		{
			SupersetInitializer::requestServerGenerationRefresh();
		}

		return new Result();
	}

	private function holdsLocalDataGeneration(string $generation): bool
	{
		$localDataGeneration = $this->config->getLocalDataGeneration();

		return $generation !== '' && $localDataGeneration !== '' && $generation === $localDataGeneration;
	}

	private function holdsServerGeneration(string $generation): bool
	{
		return $generation !== '' && $this->config->getServerGeneration() === $generation;
	}

	private function refuseUnconfirmedRebind(string $targetGeneration): Result
	{
		$result = (new Result())->addError(new Error(
			'Target superset server does not hold the generation of the local data',
			self::ERROR_GENERATION_CONFIRMATION_REQUIRED,
		));

		SupersetInitializerLogger::logWarning($result->getErrors(), [
			'targetGeneration' => $targetGeneration,
			'localDataGeneration' => $this->config->getLocalDataGeneration(),
		]);

		return $result;
	}

	private function next(): Result
	{
		$stage = $this->getRegisterStage();

		return match ($stage)
		{
			0 => $this->registerPortalId(),
			1 => $this->verifyPortalId(),
			default => (function() {
				$this->setRegisterStage(0);
				new Result();
			})(),
		};
	}

	private function registerPortalId(): Result
	{
		if ($this->config->getPortalId())
		{
			$this->setRegisterStage($this->getRegisterStage() + 1);

			return new Result();
		}

		$result = self::registerPortal();
		if ($result->isSuccess())
		{
			$this->setRegisterStage($this->getRegisterStage() + 1);
		}

		return $result;
	}

	private function verifyPortalId(): Result
	{
		if ($this->config->isPortalIdVerified())
		{
			$this->setRegisterStage($this->getRegisterStage() + 1);

			return new Result();
		}

		$result = self::verifyPortal();
		if ($result->isSuccess())
		{
			$this->config->setPortalIdVerified(true);
			$this->setRegisterStage($this->getRegisterStage() + 1);
		}
		else
		{
			$this->setRegisterStage(0);
			$this->config->clearConfig(__CLASS__ . '::' . __FUNCTION__);
		}

		return $result;
	}

	private function getRegisterStage(): int
	{
		return (int)Option::get('biconnector', self::REGISTER_STAGE_OPTION, 0);
	}

	private function setRegisterStage(int $stage): void
	{
		Option::set('biconnector', self::REGISTER_STAGE_OPTION, $stage);
	}

	private static function registerPortal(): Result
	{
		$result = new Result();
		if (!empty(ConfigContainer::getConfigContainer()->getPortalId()))
		{
			return $result;
		}

		$response = IntegratorFactory::getInstance()->registerPortal();
		$responseData = $response->getData();

		if ($response->getStatus() === IntegratorResponse::STATUS_CREATED)
		{
			$registeredPortalId = (string)($responseData['portalId'] ?? '');
			if ($registeredPortalId !== '')
			{
				ConfigContainer::getConfigContainer()->setPortalId($registeredPortalId);
			}

			ConfigContainer::getConfigContainer()->setPortalIdVerified(true);
			ConfigContainer::getConfigContainer()->setServerGeneration((string)($responseData['generation'] ?? ''));

			return $result;
		}

		if ($result->isSuccess() && $response->getStatus() === IntegratorResponse::STATUS_IN_PROGRESS)
		{
			ConfigContainer::getConfigContainer()->setPortalId($responseData['portalId'] ?? '');
			ConfigContainer::getConfigContainer()->setServerGeneration((string)($responseData['generation'] ?? ''));
		}
		else
		{
			SupersetInitializerLogger::logErrors([new Error('Register request end with errors'), ...$result->getErrors()], [
				'STATUS_CODE' => $response->getStatus(),
			]);

			$result->addErrors($response->getErrors());
			$result->setData([
				'STATUS_CODE' => $response->getStatus(),
			]);
		}

		return $result;
	}

	private static function verifyPortal(): Result
	{
		$result = new Result();
		$response = IntegratorFactory::getInstance()->verifyPortal();
		if ($response->getStatus() !== IntegratorResponse::STATUS_OK)
		{
			SupersetInitializerLogger::logErrors([new Error('Verify register request end with errors'), ...$response->getErrors()], [
				'STATUS_CODE' => $response->getStatus(),
			]);

			$result->addErrors([new Error("Invalid response status code from verify: {$response->getStatus()}"), ...$response->getErrors()]);
		}

		return $result;
	}
}
