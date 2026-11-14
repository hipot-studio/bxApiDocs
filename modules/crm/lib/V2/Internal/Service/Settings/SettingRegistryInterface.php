<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingRegistration;
use Bitrix\Crm\V2\Internal\Exception\Settings\SettingNotFoundException;
use Bitrix\Main\Result;

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
	public function getRegistrationById(string $id): SettingRegistration;

	/** @return list<SettingRegistration> */
	public function getReadableRegistrations(UserPermissions $permissions): array;

	public function canRead(SettingRegistration $registration, UserPermissions $permissions): bool;
	public function checkReadAccess(SettingRegistration $registration, UserPermissions $permissions): Result;

	public function canWrite(SettingRegistration $registration, UserPermissions $permissions): bool;
	public function checkWriteAccess(SettingRegistration $registration, UserPermissions $permissions): Result;

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
	public function getById(string $id): SettingInterface;

	/**
	 * @template TValue
	 * @param class-string<SettingInterface<TValue>> $settingClass
	 * @return TValue
	 * @throws SettingNotFoundException
	 */
	public function getValue(string $settingClass): mixed;
}
