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
final class DefaultListViewSetting extends AbstractSetting
{
	public const CODE = 'crm.activity.default_list_view';

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
		foreach ($this->activitySettings::getViewDescriptions() as $value => $title)
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
		return $this->activitySettings->getDefaultListViewID();
	}

	protected function apply(mixed $value): Result
	{
		$this->activitySettings->setDefaultListViewID($value);

		return new Result();
	}
}
