<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\History;

use Bitrix\Crm\Settings\HistorySettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\IntegerSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<int>
 * @internal
 */
final class ViewEventGroupingIntervalSetting extends AbstractSetting
{
	public const CODE = 'crm.history.view_event_grouping_interval';

	private readonly HistorySettings $historySettings;

	public function __construct(?HistorySettings $historySettings = null)
	{
		$this->historySettings = $historySettings ?? HistorySettings::getCurrent();
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
			new IntegerSchema(),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): int
	{
		return $this->historySettings->getViewEventGroupingInterval();
	}

	protected function apply(mixed $value): Result
	{
		$this->historySettings->setViewEventGroupingInterval($value);

		return new Result();
	}
}
