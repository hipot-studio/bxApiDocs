<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Invoice;

use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class PublicBitrix24SignatureEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.invoice.public_bitrix24_signature.enabled';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

	private readonly InvoiceSettings $invoiceSettings;

	public function __construct(?InvoiceSettings $invoiceSettings = null)
	{
		$this->invoiceSettings = $invoiceSettings ?? InvoiceSettings::getCurrent();
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
		return $this->invoiceSettings->getEnableSignFlag();
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->invoiceSettings->isOldInvoicesEnabled() || !$this->isDisableAllowed())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		$this->invoiceSettings->setEnableSignFlag($value);

		return new Result();
	}

	private function isDisableAllowed(): bool
	{
		return InvoiceSettings::allowDisableSign();
	}
}
