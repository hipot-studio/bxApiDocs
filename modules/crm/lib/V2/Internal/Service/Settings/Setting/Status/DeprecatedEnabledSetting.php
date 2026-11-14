<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Status;

use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class DeprecatedEnabledSetting extends AbstractSetting
{
	public const ID = 'crm.status.deprecated.enabled';

	public function getId(): string
	{
		return self::ID;
	}

	public function getDescription(): SettingDescription
	{
		return new SettingDescription(
			self::ID,
			SettingTitleProvider::getTitle(self::ID),
			new BooleanSchema(),
			SettingTitleProvider::getDescription(self::ID),
			SettingTitleProvider::getNote(self::ID),
			'enable_depricated_statuses',
		);
	}

	public function getValue(): bool
	{
		return \CCrmStatus::IsDepricatedTypesEnabled();
	}

	protected function apply(mixed $value): Result
	{
		\CCrmStatus::EnableDepricatedTypes($value);

		return new Result();
	}
}
