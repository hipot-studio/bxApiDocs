<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Format;

use Bitrix\Crm\Format\PersonNameFormatter;
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
final class PersonNameSetting extends AbstractSetting
{
	public const CODE = 'crm.format.person_name';

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		$options = [];
		foreach ($this->getDescriptions() as $value => $title)
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
		return PersonNameFormatter::getFormatID();
	}

	protected function apply(mixed $value): Result
	{
		PersonNameFormatter::setFormatID($value);

		return new Result();
	}

	/** @return array<int, string> */
	private function getDescriptions(): array
	{
		return PersonNameFormatter::getAllDescriptions();
	}
}
