<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Copilot;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class AutomaticCallProcessingEnabledSetting extends AbstractSetting
{
	public const ID = 'crm.copilot.call_processing.automatic.enabled';

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
			'AI_CALL_PROCESSING_ALLOWED_AUTO_V2',
		);
	}

	public function getValue(): bool
	{
		return AIManager::isAiCallAutomaticProcessingAllowed();
	}

	protected function apply(mixed $value): Result
	{
		AIManager::setAiCallAutomaticProcessingAllowed($value);

		return new Result();
	}
}
