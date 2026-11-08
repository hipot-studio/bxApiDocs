<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingRegistration;
use Bitrix\Crm\V2\Internal\Exception\Settings\SettingNotFoundException;

/**
 * @internal
 */
interface SettingRegistryInterface
{
	/** @return list<SettingInterface> */
	public function getAll(): array;

	/**
	 * @param class-string<SettingInterface> $settingClass
	 * @throws SettingNotFoundException
	 */
	public function getRegistration(string $settingClass): SettingRegistration;

	/**
	 * @throws SettingNotFoundException
	 */
	public function getRegistrationByCode(string $code): SettingRegistration;

	/** @return list<SettingRegistration> */
	public function getReadableRegistrations(UserPermissions $permissions): array;

	public function canRead(SettingRegistration $registration, UserPermissions $permissions): bool;

	public function canWrite(SettingRegistration $registration, UserPermissions $permissions): bool;

	/**
	 * @template TSetting of SettingInterface
	 * @param class-string<TSetting> $settingClass
	 * @return TSetting
	 * @throws SettingNotFoundException
	 */
	public function get(string $settingClass): SettingInterface;

	/**
	 * @throws SettingNotFoundException
	 */
	public function getByCode(string $code): SettingInterface;

	/**
	 * @template TValue
	 * @param class-string<SettingInterface<TValue>> $settingClass
	 * @return TValue
	 * @throws SettingNotFoundException
	 */
	public function getValue(string $settingClass): mixed;
}
