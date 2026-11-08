<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\V2\Internal\Entity\Settings\SettingRegistration;
use Bitrix\Crm\V2\Internal\Exception\Settings\SettingNotFoundException;

/**
 * @internal
 */
interface SettingFactoryInterface
{
	/** @return iterable<SettingRegistration> */
	public function getDefinitions(): iterable;

	/**
	 * @template TSetting of SettingInterface
	 * @param class-string<TSetting> $settingClass
	 * @return TSetting
	 * @throws SettingNotFoundException
	 */
	public function create(string $settingClass): SettingInterface;
}
