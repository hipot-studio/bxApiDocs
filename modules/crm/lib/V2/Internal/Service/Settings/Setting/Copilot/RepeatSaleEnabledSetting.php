<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Copilot;

use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class RepeatSaleEnabledSetting extends AbstractTuningBooleanSetting
{
	public const ID = 'crm.copilot.repeat_sale.enabled';

	public function getId(): string
	{
		return self::ID;
	}

	protected function getGlobalSetting(): GlobalSetting
	{
		return GlobalSetting::RepeatSale;
	}
}
