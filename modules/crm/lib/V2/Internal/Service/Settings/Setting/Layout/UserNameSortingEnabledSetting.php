<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Layout;

use Bitrix\Crm\Settings\LayoutSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class UserNameSortingEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.layout.user_name_sorting.enabled';

	private readonly LayoutSettings $layoutSettings;

	public function __construct(?LayoutSettings $layoutSettings = null)
	{
		$this->layoutSettings = $layoutSettings ?? LayoutSettings::getCurrent();
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
		return $this->layoutSettings->isUserNameSortingEnabled();
	}

	protected function apply(mixed $value): Result
	{
		$this->layoutSettings->enableUserNameSorting($value);

		return new Result();
	}
}
