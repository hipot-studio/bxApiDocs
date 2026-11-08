<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Copilot;

use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class AnalyzeCommunicationEnabledSetting extends AbstractTuningBooleanSetting
{
	public const CODE = 'crm.copilot.analyze_communication.enabled';

	public function getCode(): string
	{
		return self::CODE;
	}

	protected function getGlobalSetting(): GlobalSetting
	{
		return GlobalSetting::AnalyzeCommunication;
	}
}
