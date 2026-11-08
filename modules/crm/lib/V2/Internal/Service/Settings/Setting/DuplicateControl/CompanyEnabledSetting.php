<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\DuplicateControl;

use Bitrix\Crm\Integrity\DuplicateControl;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class CompanyEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.duplicate_control.company.enabled';

	public function __construct(private readonly ?DuplicateControl $duplicateControl = null)
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
			new BooleanSchema(),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): bool
	{
		return $this->getDuplicateControl()->isEnabledFor(\CCrmOwnerType::Company);
	}

	protected function apply(mixed $value): Result
	{
		$duplicateControl = $this->getDuplicateControl();
		$duplicateControl->enabledFor(\CCrmOwnerType::Company, $value);
		$duplicateControl->save();

		return new Result();
	}

	private function getDuplicateControl(): DuplicateControl
	{
		return $this->duplicateControl ?? DuplicateControl::getCurrent();
	}
}
