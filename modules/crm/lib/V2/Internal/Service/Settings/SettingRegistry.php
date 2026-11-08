<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingPolicyEvaluator;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingRegistration;
use Bitrix\Crm\V2\Internal\Exception\Settings\DuplicateSettingException;
use Bitrix\Crm\V2\Internal\Exception\Settings\SettingNotFoundException;
use LogicException;

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
	private readonly array $registrationsByCode;

	/** @var array<class-string<SettingInterface>, SettingInterface> */
	private array $settingsByClass = [];

	public function __construct(private readonly SettingFactoryInterface $factory)
	{
		$registrations = [];
		$registrationsByClass = [];
		$registrationsByCode = [];

		foreach ($factory->getDefinitions() as $registration)
		{
			$settingClass = $registration->settingClass;
			if (isset($registrationsByClass[$settingClass]))
			{
				throw new DuplicateSettingException($settingClass);
			}

			$code = $registration->code;
			if (isset($registrationsByCode[$code]))
			{
				throw new DuplicateSettingException($code);
			}

			$registrations[] = $registration;
			$registrationsByClass[$settingClass] = $registration;
			$registrationsByCode[$code] = $registration;
		}

		$this->registrations = $registrations;
		$this->registrationsByClass = $registrationsByClass;
		$this->registrationsByCode = $registrationsByCode;
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

	public function getRegistrationByCode(string $code): SettingRegistration
	{
		if (!isset($this->registrationsByCode[$code]))
		{
			throw new SettingNotFoundException($code);
		}

		return $this->registrationsByCode[$code];
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
		return SettingPolicyEvaluator::evaluateAll(
			$registration->readPolicies,
			$this->get($registration->settingClass),
			$permissions,
		);
	}

	public function canWrite(SettingRegistration $registration, UserPermissions $permissions): bool
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

		$actualCode = $setting->getCode();
		$expectedCode = $registration->code;
		if ($actualCode !== $expectedCode)
		{
			throw new LogicException(
				"CRM setting '{$settingClass}' has code '{$actualCode}', expected '{$expectedCode}'.",
			);
		}

		$this->settingsByClass[$settingClass] = $setting;

		return $setting;
	}

	public function getByCode(string $code): SettingInterface
	{
		return $this->get($this->getRegistrationByCode($code)->settingClass);
	}

	public function getValue(string $settingClass): mixed
	{
		return $this->get($settingClass)->getValue();
	}
}
