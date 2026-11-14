<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Format;

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
final class SimpleTimeEnabledSetting extends AbstractSetting
{
	public const ID = 'crm.format.simple_time.enabled';

	private readonly LayoutSettings $layoutSettings;

	public function __construct(?LayoutSettings $layoutSettings = null)
	{
		$this->layoutSettings = $layoutSettings ?? LayoutSettings::getCurrent();
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
			'enable_simple_time_format',
		);
	}

	public function getValue(): bool
	{
		return $this->layoutSettings->isSimpleTimeFormatEnabled();
	}

	protected function apply(mixed $value): Result
	{
		$this->layoutSettings->enableSimpleTimeFormat($value);

		return new Result();
	}
}
