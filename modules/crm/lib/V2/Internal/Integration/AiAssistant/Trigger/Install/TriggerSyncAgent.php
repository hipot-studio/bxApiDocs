<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Install;

use Bitrix\AiAssistant\Trigger\Dto\ModuleTriggerDto;
use Bitrix\AiAssistant\Trigger\Dto\TriggerMainInfoDto;
use Bitrix\AiAssistant\Trigger\Dto\TriggerRegistration;
use Bitrix\AiAssistant\Trigger\Enum\ModulesEnum;
use Bitrix\AiAssistant\Trigger\Enum\RestartRuleEnum;
use Bitrix\AiAssistant\Trigger\Repository\AccessRepository;
use Bitrix\AiAssistant\Trigger\Repository\TriggerRepository;
use Bitrix\AiAssistant\Trigger\Service\TriggerManagerService;
use Bitrix\AiAssistant\Trigger\TriggerRegistry;
use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\CrmSetupOnboardingAction;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary\AbstractClientSummaryAction;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary\CompanySummaryAction;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary\ContactSummaryAction;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Psr\Log\LoggerInterface;

/**
 * Brings the trigger rows of the aiassistant engine to the registry declared here.
 *
 * The registry is the source of truth, the database is its projection. The agent stays in the
 * schedule while at least one trigger is not synchronized yet: the engine may be unavailable on
 * a fresh portal, and the registration lock may be held by a concurrent hit.
 *
 * The projection goes one way: a declared trigger is created and updated, while a trigger dropped from
 * the registry keeps its row in the database and has to be removed by hand - the engine offers nothing
 * to withdraw a registration with.
 *
 * The engine does not expose priority, urlMatchType and the set of modules back, so a change of
 * those is announced by REGISTRY_VERSION instead of being detected by comparison.
 *
 * Access codes are the one exception to the projection: the declared codes are merged with the
 * stored ones to keep the grants issued at runtime, so the registry can only widen the audience of
 * a trigger. A code dropped from the registry comes back on the next update and has to be revoked
 * by hand.
 *
 * @internal
 */
final class TriggerSyncAgent extends AgentBase
{
	private const AGENT_CONTINUE = true;
	private const AGENT_STOP = false;
	private const RETRY_PERIOD_SECONDS = 1200;
	private const ENGINE_ABSENT_RETRY_PERIOD_SECONDS = 86400;
	private const MODULE_ID = 'crm';
	private const LOGGER_ID = 'Agent';

	/**
	 * Bump on any change of the registry parameters the engine does not report back.
	 *
	 * A bump alone changes nothing: the agent leaves the schedule once the registry is synchronized,
	 * so the new version has to be shipped together with a new updater that declares the agent again.
	 * Declaring it again is safe, CAgent::AddAgent inserts the row only when it is missing.
	 */
	private const REGISTRY_VERSION = 2;
	private const REGISTRY_VERSION_OPTION = 'aiassistant_trigger_registry_version';

	/**
	 * Synchronizes every declared trigger, one failure does not stop the others.
	 *
	 * @return bool true keeps the agent in the schedule, false removes it.
	 */
	public static function doRun(): bool
	{
		// the module can be installed later, so a portal without it waits instead of retrying often
		if (!ModuleManager::isModuleInstalled('aiassistant'))
		{
			(new self())->setExecutionPeriod(self::ENGINE_ABSENT_RETRY_PERIOD_SECONDS);

			return self::AGENT_CONTINUE;
		}

		if (!self::isTriggerEngineAvailable())
		{
			(new self())->setExecutionPeriod(self::RETRY_PERIOD_SECONDS);

			return self::AGENT_CONTINUE;
		}

		$isEverythingSynchronized = true;

		foreach (self::getTriggerRegistrations() as $buildRegistration)
		{
			try
			{
				if (!self::registerOrUpdate($buildRegistration))
				{
					$isEverythingSynchronized = false;
				}
			}
			catch (\Throwable $exception)
			{
				$isEverythingSynchronized = false;
				self::getLogger()->error(
					'crm trigger sync failed: {message}',
					['message' => $exception->getMessage()],
				);
			}
		}

		if (!$isEverythingSynchronized)
		{
			return self::AGENT_CONTINUE;
		}

		// the version covers the whole registry, so it is applied once every element is in place
		return self::applyRegistryVersion() ? self::AGENT_STOP : self::AGENT_CONTINUE;
	}

	/**
	 * Registrations are built one by one on purpose: a broken declaration must fail its own
	 * iteration instead of the whole pass. Each builder takes the IS_ACTIVE value to declare,
	 * which is how the stored one is carried over into update().
	 *
	 * @return iterable<\Closure(int $isActive): TriggerRegistration>
	 */
	private static function getTriggerRegistrations(): iterable
	{
		return [
			static fn(int $isActive): TriggerRegistration => self::createCrmSetupOnboardingRegistration($isActive),
			static fn(int $isActive): TriggerRegistration => self::createClientSummaryRegistration(
				ContactSummaryAction::class,
				$isActive,
			),
			static fn(int $isActive): TriggerRegistration => self::createClientSummaryRegistration(
				CompanySummaryAction::class,
				$isActive,
			),
		];
	}

	private static function createCrmSetupOnboardingRegistration(int $isActive): TriggerRegistration
	{
		return new TriggerRegistration(
			className: CrmSetupOnboardingAction::class,
			moduleName: 'crm',
			modulesForStart: [new ModuleTriggerDto(ModulesEnum::Crm)],
			accessCodes: ['UA'],
			// must stay above FirstMsgWithWidgetAction (1000), it competes for the same audience
			priority: 1100,
			enableUserLock: true,
			userLockTime: 120,
			restartRule: RestartRuleEnum::NextDay,
			restartTimeValue: 3,
			isActive: $isActive,
		);
	}

	/**
	 * The summary chat is opened by a button of the entity card, so everything that shapes the
	 * automatic display is left out: the user lock, the restart rule and the priority of the
	 * display queue have nothing to arbitrate here.
	 *
	 * @param class-string<AbstractClientSummaryAction> $className
	 */
	private static function createClientSummaryRegistration(string $className, int $isActive): TriggerRegistration
	{
		return new TriggerRegistration(
			className: $className,
			moduleName: 'crm',
			modulesForStart: [new ModuleTriggerDto(ModulesEnum::Crm)],
			accessCodes: ['UA'],
			enableUserLock: false,
			restartRule: null,
			isActive: $isActive,
		);
	}

	/**
	 * Copies the registration with the granted access codes added to the declared ones.
	 *
	 * TriggerRegistration is readonly and update() replaces the whole set of codes, so the merged
	 * set can only reach the engine through a copy. Every field of the engine dto is carried over.
	 */
	private static function withGrantedAccessCodes(
		TriggerRegistration $registration,
		array $grantedAccessCodes,
	): TriggerRegistration
	{
		return new TriggerRegistration(
			className: $registration->className,
			moduleName: $registration->moduleName,
			modulesForStart: $registration->modulesForStart,
			accessCodes: array_values(array_unique([...$registration->accessCodes, ...$grantedAccessCodes])),
			priority: $registration->priority,
			enableUserLock: $registration->enableUserLock,
			userLockTime: $registration->userLockTime,
			enableConcurrencyLock: $registration->enableConcurrencyLock,
			concurrencyLockTime: $registration->concurrencyLockTime,
			restartRule: $registration->restartRule,
			restartTimeValue: $registration->restartTimeValue,
			isActive: $registration->isActive,
			urlMatchType: $registration->urlMatchType,
		);
	}

	/**
	 * @param \Closure(int $isActive): TriggerRegistration $buildRegistration
	 */
	private static function registerOrUpdate(\Closure $buildRegistration): bool
	{
		$manager = self::getTriggerManager();
		$registration = $buildRegistration(1);
		$registerDto = $registration->toRegisterDto();

		[$isCreated, $triggerId] = $manager->register($registerDto);
		if ($isCreated)
		{
			return true;
		}

		// the lock is held by a concurrent hit or the module is unavailable, nothing was written
		if ($triggerId <= 0)
		{
			return false;
		}

		$current = self::getTriggerRepository()->getMainInfoByClassName($registration->className);
		if ($current === null)
		{
			return false;
		}

		if (!self::isRegistryVersionChanged() && !self::fieldsDiffer($current, $registration))
		{
			return true;
		}

		// update() always writes IS_ACTIVE from the dto and replaces the access codes wholesale, so
		// the stored active flag and the codes granted at runtime are carried over explicitly
		$updateRegistration = self::withGrantedAccessCodes(
			$buildRegistration($current->isActive ? 1 : 0),
			self::getAccessRepository()->getCodesByTriggerId($triggerId),
		);

		if (!$manager->update($updateRegistration->toRegisterDto()))
		{
			self::getLogger()->error(
				'crm trigger sync update failed, code: {code}',
				['code' => $registerDto->code],
			);

			return false;
		}

		return true;
	}

	private static function applyRegistryVersion(): bool
	{
		try
		{
			Option::set(self::MODULE_ID, self::REGISTRY_VERSION_OPTION, (string)self::REGISTRY_VERSION);
		}
		catch (\Throwable $exception)
		{
			self::getLogger()->error(
				'crm trigger sync could not store the registry version: {message}',
				['message' => $exception->getMessage()],
			);

			return false;
		}

		return true;
	}

	/**
	 * Compares only the fields the engine reports back through TriggerMainInfoDto.
	 */
	private static function fieldsDiffer(TriggerMainInfoDto $current, TriggerRegistration $registration): bool
	{
		return
			$current->code !== $registration->className::getCode()
			|| $current->moduleName !== $registration->moduleName
			|| $current->restartRule !== $registration->restartRule?->value
			|| $current->restartTimeValue !== $registration->restartTimeValue
			|| $current->enableUserLock !== $registration->enableUserLock
			|| $current->userLockTime !== $registration->userLockTime
			|| $current->enableConcurrencyLock !== $registration->enableConcurrencyLock
			|| $current->concurrencyLockTime !== $registration->concurrencyLockTime;
	}

	private static function isRegistryVersionChanged(): bool
	{
		$appliedVersion = Option::get(self::MODULE_ID, self::REGISTRY_VERSION_OPTION, '');

		return $appliedVersion !== (string)self::REGISTRY_VERSION;
	}

	/**
	 * The agent is scheduled unconditionally, so it has to survive a portal without aiassistant
	 * and a portal where the engine has no trigger registry yet.
	 */
	private static function isTriggerEngineAvailable(): bool
	{
		try
		{
			return Loader::includeModule('aiassistant')
				// the facade stands for an intact public API of the engine, the operations themselves
				// are taken from TriggerManagerService
				&& class_exists(TriggerRegistry::class)
				&& class_exists(TriggerRegistration::class)
				&& class_exists(ModuleTriggerDto::class)
				&& class_exists(TriggerManagerService::class)
				&& class_exists(TriggerRepository::class)
				&& class_exists(AccessRepository::class)
				&& class_exists(CrmSetupOnboardingAction::class)
				&& enum_exists(ModulesEnum::class)
				&& ModulesEnum::tryFrom(self::MODULE_ID) !== null
				&& enum_exists(RestartRuleEnum::class);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private static function getTriggerManager(): TriggerManagerService
	{
		return ServiceLocator::getInstance()->get(TriggerManagerService::class);
	}

	private static function getTriggerRepository(): TriggerRepository
	{
		return ServiceLocator::getInstance()->get(TriggerRepository::class);
	}

	private static function getAccessRepository(): AccessRepository
	{
		return ServiceLocator::getInstance()->get(AccessRepository::class);
	}

	private static function getLogger(): LoggerInterface
	{
		return Container::getInstance()->getLogger(self::LOGGER_ID);
	}
}
