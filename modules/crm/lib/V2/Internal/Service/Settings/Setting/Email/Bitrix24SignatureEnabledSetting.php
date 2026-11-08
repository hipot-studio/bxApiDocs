<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Email;

use Bitrix\Crm\Integration\Bitrix24Email;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class Bitrix24SignatureEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.email.bitrix24_signature.enabled';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

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
		return Bitrix24Email::isSignatureEnabled();
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->isIntegrationEnabled() || !$this->isDisableAllowed())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		Bitrix24Email::enableSignature($value);

		return new Result();
	}

	private function isIntegrationEnabled(): bool
	{
		return Bitrix24Email::isEnabled();
	}

	private function isDisableAllowed(): bool
	{
		return Bitrix24Email::allowDisableSignature();
	}
}
