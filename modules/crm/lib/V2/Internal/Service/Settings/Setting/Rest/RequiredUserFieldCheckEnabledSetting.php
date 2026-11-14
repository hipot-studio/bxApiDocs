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
	public const ID = 'crm.rest.required_user_field_check.enabled';

	private readonly RestSettings $restSettings;

	public function __construct(?RestSettings $restSettings = null)
	{
		$this->restSettings = $restSettings ?? RestSettings::getCurrent();
	}

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
			'rest_enable_req_uf_check',
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
