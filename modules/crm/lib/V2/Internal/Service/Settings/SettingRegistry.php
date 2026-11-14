<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingPolicyEvaluator;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingRegistration;
use Bitrix\Crm\V2\Internal\Exception\Settings\DuplicateSettingException;
use Bitrix\Crm\V2\Internal\Exception\Settings\SettingNotFoundException;
use LogicException;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class SettingRegistry implements SettingRegistryInterface
{
	/** @var list<SettingRegistration> */
	private readonly array $registrations;

	/** @var array<class-string<SettingInterface>, SettingRegistration> */
	private readonly array $registrationsByClass;

	/** @var array<string, SettingRegistration> */
	private readonly array $registrationsById;

	/** @var array<class-string<SettingInterface>, SettingInterface> */
	private array $settingsByClass = [];

	public function __construct(private readonly SettingFactoryInterface $factory)
	{
		$registrations = [];
		$registrationsByClass = [];
		$registrationsById = [];

		foreach ($factory->getDefinitions() as $registration)
		{
			$settingClass = $registration->settingClass;
			if (isset($registrationsByClass[$settingClass]))
			{
				throw new DuplicateSettingException($settingClass);
			}

			$id = $registration->id;
			if (isset($registrationsById[$id]))
			{
				throw new DuplicateSettingException($id);
			}

			$registrations[] = $registration;
			$registrationsByClass[$settingClass] = $registration;
			$registrationsById[$id] = $registration;
		}

		$this->registrations = $registrations;
		$this->registrationsByClass = $registrationsByClass;
		$this->registrationsById = $registrationsById;
	}

	public function getAll(): array
	{
		$settings = [];
		foreach ($this->registrations as $registration)
		{
			$settings[] = $this->get($registration->settingClass);
		}

		return $settings;
	}

	public function getRegistration(string $settingClass): SettingRegistration
	{
		if (!isset($this->registrationsByClass[$settingClass]))
		{
			throw new SettingNotFoundException($settingClass);
		}

		return $this->registrationsByClass[$settingClass];
	}

	public function getRegistrationById(string $id): SettingRegistration
	{
		if (!isset($this->registrationsById[$id]))
		{
			throw new SettingNotFoundException($id);
		}

		return $this->registrationsById[$id];
	}

	public function getReadableRegistrations(UserPermissions $permissions): array
	{
		$readableRegistrations = [];
		foreach ($this->registrations as $registration)
		{
			if ($this->canRead($registration, $permissions))
			{
				$readableRegistrations[] = $registration;
			}
		}

		return $readableRegistrations;
	}

	public function canRead(SettingRegistration $registration, UserPermissions $permissions): bool
	{
		return $this->checkReadAccess($registration, $permissions)->isSuccess();
	}

	public function checkReadAccess(SettingRegistration $registration, UserPermissions $permissions): Result
	{
		return SettingPolicyEvaluator::evaluateAll(
			$registration->readPolicies,
			$this->get($registration->settingClass),
			$permissions,
		);
	}

	public function canWrite(SettingRegistration $registration, UserPermissions $permissions): bool
	{
		return $this->checkWriteAccess($registration, $permissions)->isSuccess();
	}

	public function checkWriteAccess(SettingRegistration $registration, UserPermissions $permissions): Result
	{
		return SettingPolicyEvaluator::evaluateAll(
			$registration->writePolicies,
			$this->get($registration->settingClass),
			$permissions,
		);
	}

	public function get(string $settingClass): SettingInterface
	{
		$registration = $this->getRegistration($settingClass);

		if (isset($this->settingsByClass[$settingClass]))
		{
			return $this->settingsByClass[$settingClass];
		}

		$setting = $this->factory->create($settingClass);
		$actualClass = $setting::class;
		if ($actualClass !== $settingClass)
		{
			throw new LogicException(
				"Setting factory returned '{$actualClass}' for '{$settingClass}'.",
			);
		}

		$actualId = $setting->getId();
		$expectedId = $registration->id;
		if ($actualId !== $expectedId)
		{
			throw new LogicException(
				"CRM setting '{$settingClass}' has id '{$actualId}', expected '{$expectedId}'.",
			);
		}

		$this->settingsByClass[$settingClass] = $setting;

		return $setting;
	}

	public function getById(string $id): SettingInterface
	{
		return $this->get($this->getRegistrationById($id)->settingClass);
	}

	public function getValue(string $settingClass): mixed
	{
		return $this->get($settingClass)->getValue();
	}
}
