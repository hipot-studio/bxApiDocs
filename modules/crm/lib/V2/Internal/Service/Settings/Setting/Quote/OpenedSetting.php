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
	public const ID = 'crm.quote.opened';

	private readonly QuoteSettings $quoteSettings;

	public function __construct(?QuoteSettings $quoteSettings = null)
	{
		$this->quoteSettings = $quoteSettings ?? QuoteSettings::getCurrent();
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
			'quote_opened_flag',
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
