<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Activity;

use Bitrix\Crm\Settings\ActivitySettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingOption;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SelectSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<int>
 * @internal
 */
final class EntityBadgeRemovalDaysSetting extends AbstractSetting
{
	public const CODE = 'crm.activity.entity_badge_removal_days';

	private readonly ActivitySettings $activitySettings;

	public function __construct(?ActivitySettings $activitySettings = null)
	{
		$this->activitySettings = $activitySettings ?? ActivitySettings::getCurrent();
	}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		$options = [];
		foreach ($this->activitySettings::getBadgeTtlValues() as $value => $title)
		{
			$options[] = new SettingOption((int)$value, $title);
		}

		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new SelectSchema(...$options),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): int
	{
		return $this->activitySettings->getRemoveEntityBadgesIntervalDays();
	}

	protected function apply(mixed $value): Result
	{
		$this->activitySettings->setRemoveEntityBadgesIntervalDays($value);

		return new Result();
	}
}
