<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Rest;

use Bitrix\Crm\Settings\RestSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class RequiredUserFieldCheckEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.rest.required_user_field_check.enabled';

	private readonly RestSettings $restSettings;

	public function __construct(?RestSettings $restSettings = null)
	{
		$this->restSettings = $restSettings ?? RestSettings::getCurrent();
	}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new BooleanSchema(),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): bool
	{
		return $this->restSettings->isRequiredUserFieldCheckEnabled();
	}

	protected function apply(mixed $value): Result
	{
		$this->restSettings->enableRequiredUserFieldCheck($value);

		return new Result();
	}
}
