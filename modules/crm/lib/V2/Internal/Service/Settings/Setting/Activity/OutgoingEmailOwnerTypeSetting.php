<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Activity;

use Bitrix\Crm\Settings\ActivitySettings;
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
final class OutgoingEmailOwnerTypeSetting extends AbstractSetting
{
	public const CODE = 'crm.activity.outgoing_email_owner_type';

	private readonly ActivitySettings $activitySettings;

	public function __construct(?ActivitySettings $activitySettings = null)
	{
		$this->activitySettings = $activitySettings ?? ActivitySettings::getCurrent();
	}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		$options = [];
		foreach ([\CCrmOwnerType::Lead, \CCrmOwnerType::Contact] as $ownerTypeId)
		{
			$options[] = new SettingOption($ownerTypeId, $this->getOwnerTypeDescription($ownerTypeId));
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
		return $this->activitySettings->getOutgoingEmailOwnerTypeId();
	}

	protected function apply(mixed $value): Result
	{
		$this->activitySettings->setOutgoingEmailOwnerTypeId($value);

		return new Result();
	}

	private function getOwnerTypeDescription(int $ownerTypeId): string
	{
		return \CCrmOwnerType::getDescription($ownerTypeId);
	}
}
