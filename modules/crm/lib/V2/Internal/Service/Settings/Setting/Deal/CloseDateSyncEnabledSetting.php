<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Deal;

use Bitrix\Crm\Settings\DealSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class CloseDateSyncEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.deal.close_date_sync.enabled';

	private readonly DealSettings $dealSettings;

	public function __construct(?DealSettings $dealSettings = null)
	{
		$this->dealSettings = $dealSettings ?? DealSettings::getCurrent();
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
		return $this->dealSettings->isCloseDateSyncEnabled();
	}

	protected function apply(mixed $value): Result
	{
		$this->dealSettings->enableCloseDateSync($value);

		return new Result();
	}
}
