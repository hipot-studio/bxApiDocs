<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Copilot;

use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Integration\Ai\TuningGateway;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
abstract class AbstractTuningBooleanSetting extends AbstractSetting
{
	public function __construct(private readonly TuningGateway $tuningGateway)
	{
	}

	final public function getDescription(): SettingDescription
	{
		return new SettingDescription(
			$this->getCode(),
			SettingTitleProvider::getTitle($this->getCode()),
			new BooleanSchema(),
			SettingTitleProvider::getHint($this->getCode()),
		);
	}

	final public function getValue(): bool
	{
		return $this->tuningGateway->isEnabled($this->getGlobalSetting());
	}

	final protected function apply(mixed $value): Result
	{
		return $this->tuningGateway->setEnabled($this->getGlobalSetting(), $value);
	}

	abstract protected function getGlobalSetting(): GlobalSetting;
}
