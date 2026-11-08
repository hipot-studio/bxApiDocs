<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Email;

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
final class ServiceCodeAllocationSetting extends AbstractSetting
{
	public const CODE = 'crm.email.service_code_allocation';

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
		return \CCrmEMailCodeAllocation::GetCurrent();
	}

	protected function apply(mixed $value): Result
	{
		\CCrmEMailCodeAllocation::SetCurrent($value);

		return new Result();
	}

	private function getDescriptions(): array
	{
		return \CCrmEMailCodeAllocation::GetAllDescriptions();
	}
}
