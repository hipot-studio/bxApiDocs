<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Quote;

use Bitrix\Crm\Settings\QuoteSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class OpenedSetting extends AbstractSetting
{
	public const CODE = 'crm.quote.opened';

	private readonly QuoteSettings $quoteSettings;

	public function __construct(?QuoteSettings $quoteSettings = null)
	{
		$this->quoteSettings = $quoteSettings ?? QuoteSettings::getCurrent();
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
		return $this->quoteSettings->getOpenedFlag();
	}

	protected function apply(mixed $value): Result
	{
		$this->quoteSettings->setOpenedFlag($value);

		return new Result();
	}
}
