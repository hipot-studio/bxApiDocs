<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Deal;

use Bitrix\Crm\Field\CloseDate;
use Bitrix\Crm\Model\Field\DefaultValue\CloseDateConfigurator;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Service\Settings\ResettableSettingInterface;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\IntegerSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * @extends AbstractSetting<int>
 * @implements ResettableSettingInterface<int>
 * @internal
 */
final class DaysBeforeCloseSetting extends AbstractSetting implements ResettableSettingInterface
{
	public const CODE = 'crm.deal.days_before_close';
	private const MODULE_ID = 'crm';
	private const OPTION_NAME = 'crm_deal_days_before_close';

	public function __construct(private readonly CloseDateConfigurator $closeDateConfigurator)
	{
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
			new IntegerSchema(0, CloseDate::MAX_DAYS_BEFORE_CLOSE),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): int
	{
		return $this->closeDateConfigurator->resolveDefaultCloseDateDays(CCrmOwnerType::Deal);
	}

	public function reset(): Result
	{
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_NAME]);

		return new Result();
	}

	protected function apply(mixed $value): Result
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, $value);

		return new Result();
	}

}
