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
	public const ID = 'crm.email.bitrix24_signature.enabled';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

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
			'enable_b24_email_sign',
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
