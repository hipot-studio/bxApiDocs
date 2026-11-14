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
	public const ID = 'crm.format.person_name';

	public function getId(): string
	{
		return self::ID;
	}

	public function getDescription(): SettingDescription
	{
		$options = [];
		foreach ($this->getDescriptions() as $value => $title)
		{
			$options[] = new SettingOption((int)$value, $title);
		}

		return new SettingDescription(
			self::ID,
			SettingTitleProvider::getTitle(self::ID),
			new SelectSchema(...$options),
			SettingTitleProvider::getDescription(self::ID),
			SettingTitleProvider::getNote(self::ID),
			'prsn_nm_frmt_id',
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
