<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Format;

use Bitrix\Crm\Format\EntityAddressFormatter;
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
final class EntityAddressSetting extends AbstractSetting
{
	public const ID = 'crm.format.entity_address';

	public function getId(): string
	{
		return self::ID;
	}

	public function getDescription(): SettingDescription
	{
		$options = [];
		$examples = EntityAddressFormatter::getAllExamples(false);
		foreach ($this->getDescriptions() as $value => $title)
		{
			$example = $examples[$value] ?? null;
			$hint = $example === null
				? null
				: trim(strip_tags((string)preg_replace('~<br\s*/?>~i', "\n", $example)));
			$options[] = new SettingOption((int)$value, $title, $hint);
		}

		return new SettingDescription(
			self::ID,
			SettingTitleProvider::getTitle(self::ID),
			new SelectSchema(...$options),
			SettingTitleProvider::getDescription(self::ID),
			SettingTitleProvider::getNote(self::ID),
			'ent_addr_frmt_id',
		);
	}

	public function getValue(): int
	{
		return EntityAddressFormatter::getFormatID();
	}

	protected function apply(mixed $value): Result
	{
		EntityAddressFormatter::setFormatID($value);

		return new Result();
	}

	/** @return array<int, string> */
	private function getDescriptions(): array
	{
		return EntityAddressFormatter::getAllDescriptions(false);
	}
}
