<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\SmartInvoice;

use Bitrix\Crm\Field\CloseDate;
use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Model\Field\DefaultValue\CloseDateConfigurator;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\IntegerSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * @extends AbstractSetting<int>
 * @internal
 */
final class DaysBeforeCloseSetting extends AbstractSetting
{
	public const CODE = 'crm.smart_invoice.days_before_close';
	private const TYPE_NOT_FOUND_ERROR_CODE = 'SMART_INVOICE_TYPE_NOT_FOUND';

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
		return $this->closeDateConfigurator->resolveDefaultCloseDateDays(CCrmOwnerType::SmartInvoice);
	}

	protected function apply(mixed $value): Result
	{
		$type = $this->resolveType();
		if ($type === null)
		{
			return (new Result())->addError(
				new Error('Smart invoice type is not available.', self::TYPE_NOT_FOUND_ERROR_CODE),
			);
		}

		$type->setDaysBeforeClose($value);

		return $type->save();
	}


	private function resolveType(): ?Type
	{
		return Container::getInstance()->getTypeByEntityTypeId(CCrmOwnerType::SmartInvoice);
	}
}
