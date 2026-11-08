<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Format;

use Bitrix\Crm\V2\Internal\Entity\Settings\ObjectField;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingOption;
use Bitrix\Crm\V2\Internal\Entity\Settings\Value\CallToValue;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\ObjectSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SelectSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\StringSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<CallToValue>
 * @internal
 */
final class CallToSetting extends AbstractSetting
{
	public const CODE = 'crm.format.callto';
	private const DEFAULT_URL_TEMPLATE = 'callto:[phone]';

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		$formatOptions = [];
		foreach ($this->getFormatDescriptions() as $value => $title)
		{
			$formatOptions[] = new SettingOption((int)$value, $title);
		}

		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new ObjectSchema(
				CallToValue::class,
				new ObjectField(
					'format',
					SettingTitleProvider::getTitle(self::CODE . '.field.format'),
					new SelectSchema(...$formatOptions),
				),
				new ObjectField(
					'urlTemplate',
					SettingTitleProvider::getTitle(self::CODE . '.field.url_template'),
					new StringSchema(),
				),
				new ObjectField(
					'clickHandler',
					SettingTitleProvider::getTitle(self::CODE . '.field.click_handler'),
					new StringSchema(),
				),
				new ObjectField(
					'normalizeNumber',
					SettingTitleProvider::getTitle(self::CODE . '.field.normalize_number'),
					new BooleanSchema(),
				),
			),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): CallToValue
	{
		$customSettings = $this->getCustomSettings();

		return new CallToValue(
			$this->getFormat(),
			is_string($customSettings['URL_TEMPLATE'] ?? null)
				? $customSettings['URL_TEMPLATE']
				: self::DEFAULT_URL_TEMPLATE,
			is_string($customSettings['CLICK_HANDLER'] ?? null) ? $customSettings['CLICK_HANDLER'] : '',
			isset($customSettings['NORMALIZE_NUMBER'])
				? $customSettings['NORMALIZE_NUMBER'] === 'Y'
				: true,
		);
	}

	protected function apply(mixed $value): Result
	{
		$currentFormat = $this->getFormat();
		$currentSettings = $this->getCustomSettings();
		$newFormat = $value->getFormat();
		$newSettings = $currentSettings;
		$newSettings['URL_TEMPLATE'] = $value->getUrlTemplate();
		$newSettings['CLICK_HANDLER'] = $value->getClickHandler();
		$newSettings['NORMALIZE_NUMBER'] = $value->shouldNormalizeNumber() ? 'Y' : 'N';

		if ($currentFormat === $newFormat && $currentSettings === $newSettings)
		{
			return new Result();
		}

		$result = $this->writeSettings($newFormat, $newSettings, $currentFormat, $currentSettings);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->clearManagedCache();

		return $result;
	}

	/** @return array<int, string> */
	private function getFormatDescriptions(): array
	{
		return \CCrmCallToUrl::GetAllDescriptions();
	}

	private function getFormat(): int
	{
		return \CCrmCallToUrl::GetFormat(\CCrmCallToUrl::Bitrix);
	}

	/** @return array<string, mixed> */
	private function getCustomSettings(): array
	{
		return \CCrmCallToUrl::GetCustomSettings();
	}

	/**
	 * @param array<string, mixed> $newSettings
	 * @param array<string, mixed> $currentSettings
	 */
	private function writeSettings(
		int $newFormat,
		array $newSettings,
		int $currentFormat,
		array $currentSettings,
	): Result
	{
		$result = new Result();
		$formatChanged = $currentFormat !== $newFormat;
		$connection = null;
		$transactionStarted = false;
		$writeError = null;

		try
		{
			$connection = Application::getConnection();
			$connection->startTransaction();
			$transactionStarted = true;

			if ($formatChanged)
			{
				$writeError = new Error('Failed to save call link format.', 'CALLTO_FORMAT_WRITE_FAILED');
				if (!\CCrmCallToUrl::SetFormat($newFormat))
				{
					throw new \RuntimeException();
				}
			}

			if ($currentSettings !== $newSettings)
			{
				$writeError = new Error(
					'Failed to save custom call link settings.',
					'CALLTO_CUSTOM_SETTINGS_WRITE_FAILED',
				);
				if (!\CCrmCallToUrl::SetCustomSettings($newSettings))
				{
					throw new \RuntimeException();
				}
			}

			$writeError = null;
			$connection->commitTransaction();
			$transactionStarted = false;
		}
		catch (\Throwable)
		{
			$result->addError(
				$writeError ?? new Error('Failed to save call link settings.', 'CALLTO_WRITE_FAILED'),
			);

			if ($connection instanceof Connection)
			{
				$this->rollbackTransaction($connection, $transactionStarted, $result);
			}
		}

		return $result;
	}

	private function rollbackTransaction(Connection $connection, bool &$transactionStarted, Result $result): void
	{
		if (!$transactionStarted)
		{
			return;
		}

		$transactionStarted = false;
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
			// A nested MySQL rollback restores the savepoint before reporting that it is unsupported.
		}
		catch (\Throwable)
		{
			$result->addError(
				new Error('Failed to roll back call link settings.', 'CALLTO_ROLLBACK_FAILED'),
			);
		}
	}

	private function clearManagedCache(): void
	{
		if (defined('BX_COMP_MANAGED_CACHE'))
		{
			$GLOBALS['CACHE_MANAGER']->ClearByTag('CRM_CALLTO_SETTINGS');
		}
	}
}
