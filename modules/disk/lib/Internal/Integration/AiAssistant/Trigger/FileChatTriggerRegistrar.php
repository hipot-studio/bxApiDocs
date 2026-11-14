<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\AiAssistant\Trigger;

use Bitrix\AiAssistant\Trigger\Dto\ModuleTriggerDto;
use Bitrix\AiAssistant\Trigger\Dto\TriggerMainInfoDto;
use Bitrix\AiAssistant\Trigger\Dto\TriggerRegistration;
use Bitrix\AiAssistant\Trigger\Enum\ModulesEnum;
use Bitrix\AiAssistant\Trigger\Repository\AccessRepository;
use Bitrix\AiAssistant\Trigger\Repository\ModuleRepository;
use Bitrix\AiAssistant\Trigger\Repository\TriggerRepository;
use Bitrix\AiAssistant\Trigger\Service\TriggerManagerService;
use Bitrix\Disk\Internal\Integration\AiAssistant\Trigger\Action\FileChatTrigger;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Loader;

class FileChatTriggerRegistrar
{
	private readonly ExceptionHandler $exceptionHandler;
	private readonly FileChatTriggerCache $cache;

	public function __construct(
		private readonly TriggerRepository $repository,
		private readonly AccessRepository $accessRepository,
		private readonly ModuleRepository $moduleRepository,
		private readonly TriggerManagerService $triggerManager,
		?ExceptionHandler $exceptionHandler = null,
		?FileChatTriggerCache $cache = null,
	)
	{
		$this->exceptionHandler = $exceptionHandler ?? Application::getInstance()->getExceptionHandler();
		$this->cache = $cache ?? new FileChatTriggerCache();
	}

	/** A verified registration is remembered for the file menu, one found not ready is forgotten. */
	public function register(): bool
	{
		try
		{
			// Read ahead of the registry: the managed cache drops a write when a clear() lands after the read.
			$this->cache->isRegistrationReady();
			$isReady = $this->isAiAssistantAvailable() && $this->isReady($this->findOrRegister());
			if ($isReady)
			{
				$this->cache->rememberRegistrationReady();
			}
			else
			{
				$this->cache->forgetRegistration();
			}

			return $isReady;
		}
		catch (\Throwable $exception)
		{
			$this->exceptionHandler->writeToLog($exception);

			return false;
		}
	}

	public function ensureRegistered(): bool
	{
		return $this->register();
	}

	public function inactivate(): bool
	{
		try
		{
			return $this->isAiAssistantAvailable() && $this->inactivateOwnedRegistration();
		}
		catch (\Throwable $exception)
		{
			$this->exceptionHandler->writeToLog($exception);

			return false;
		}
		finally
		{
			// After the registry has changed, so that no menu request remembers the trigger from before it.
			$this->cache->clear();
		}
	}

	private function findOrRegister(): ?TriggerMainInfoDto
	{
		$registration = $this->repository->getMainInfoByCode(FileChatTrigger::getCode());
		if ($registration !== null)
		{
			return $registration;
		}

		$this->triggerManager->register((new TriggerRegistration(
			className: FileChatTrigger::class,
			moduleName: 'disk',
			modulesForStart: [new ModuleTriggerDto(ModulesEnum::ALL)],
			accessCodes: ['UA'],
			priority: 300,
		))->toRegisterDto());

		return $this->repository->getMainInfoByCode(FileChatTrigger::getCode());
	}

	private function inactivateOwnedRegistration(): bool
	{
		$registration = $this->repository->getMainInfoByCode(FileChatTrigger::getCode());
		if ($registration === null)
		{
			return true;
		}

		if (!self::isOwnedRegistration($registration))
		{
			return false;
		}

		if (!$registration->isActive)
		{
			return true;
		}

		$this->repository->inactivate($registration->id);
		$inactivated = $this->repository->getMainInfoByCode(FileChatTrigger::getCode());

		return self::isOwnedRegistration($inactivated)
			&& $inactivated->id === $registration->id
			&& !$inactivated->isActive;
	}

	private function isReady(?TriggerMainInfoDto $registration): bool
	{
		if (!self::isOwnedRegistration($registration) || !$registration->isActive)
		{
			return false;
		}

		$accessCodes = $this->accessRepository->getCodesByTriggerId($registration->id);
		if (!in_array('UA', $accessCodes, true))
		{
			return false;
		}

		$modules = $this->moduleRepository->getModulesByTriggerIds([$registration->id], ModulesEnum::ALL);
		foreach ($modules as $module)
		{
			if ($module['MODULE'] === ModulesEnum::ALL->value && ($module['URL'] ?? '') === '')
			{
				return true;
			}
		}

		return false;
	}

	private static function isOwnedRegistration(?TriggerMainInfoDto $registration): bool
	{
		return $registration !== null
			&& $registration->id > 0
			&& $registration->code === FileChatTrigger::getCode()
			&& $registration->classForWork === FileChatTrigger::class
			&& $registration->moduleName === 'disk';
	}

	protected function isAiAssistantAvailable(): bool
	{
		return Loader::includeModule('aiassistant');
	}
}
