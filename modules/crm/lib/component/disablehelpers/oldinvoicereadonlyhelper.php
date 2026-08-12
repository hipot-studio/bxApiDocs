<?php

namespace Bitrix\Crm\Component\DisableHelpers;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Main\Config\Option;

final class OldInvoiceReadonlyHelper extends BaseDisableHelper
{
	public const INSTALL_DATE_OPTION = 'old_invoice_sunset_install_date';
	public const WARNING_LAST_SHOWN_FIELD = 'old_invoice_warning_alert_last_shown';
	public const LEGACY_LAST_SHOWN_FIELD = 'old_invoice_readonly_alert_last_time_shown_date';

	private const SUNSET_PERIOD_DAYS = 30;
	private const LAST_TIME_SHOWN_OPTION_NAME = 'timestamp';

	private const PHASE_WARNING = 'warning';
	private const PHASE_READONLY = 'readonly';

	private static ?bool $readOnlyCache = null;

	public static function isOldInvoiceReadOnly(int $entityTypeId): bool
	{
		if ($entityTypeId !== \CCrmOwnerType::Invoice)
		{
			return false;
		}

		return self::computeReadOnly();
	}

	public function shouldEnforceReadOnly(): bool
	{
		return self::computeReadOnly();
	}

	public function canShowAlert(): bool
	{
		if (!InvoiceSettings::getCurrent()->isOldInvoicesEnabled())
		{
			return false;
		}

		if ($this->getCurrentPhase() === self::PHASE_READONLY)
		{
			return true;
		}

		if (Container::getInstance()->getUserPermissions()->isCrmAdmin())
		{
			return true;
		}

		$daysSinceLastTimeShown = $this->getDaysSinceLastTimeShown(
			self::WARNING_LAST_SHOWN_FIELD,
			self::LAST_TIME_SHOWN_OPTION_NAME,
		);

		return $daysSinceLastTimeShown === null;
	}

	public function getJsParams(array $context = []): array
	{
		$isAdmin = Container::getInstance()->getUserPermissions()->isCrmAdmin();

		if ($this->getCurrentPhase() === self::PHASE_WARNING)
		{
			$contentOptions = [
				'daysUntilDisable' => $this->getDaysLeftUntilDisable(),
				'isAdmin' => $isAdmin,
			];

			if (!$isAdmin)
			{
				$contentOptions['lastTimeShownField'] = self::WARNING_LAST_SHOWN_FIELD;
				$contentOptions['lastTimeShownOptionName'] = self::LAST_TIME_SHOWN_OPTION_NAME;
			}

			return [
				'contentName' => AlertContent::OLD_INVOICE_WARNING->value,
				'contentOptions' => $contentOptions,
			];
		}

		return [
			'contentName' => AlertContent::OLD_INVOICE_READONLY->value,
			'contentOptions' => [
				'isAdmin' => $isAdmin,
			],
		];
	}

	private static function computeReadOnly(): bool
	{
		if (self::$readOnlyCache !== null)
		{
			return self::$readOnlyCache;
		}

		if (!InvoiceSettings::getCurrent()->isOldInvoicesEnabled())
		{
			return self::$readOnlyCache = true;
		}

		$installTimestamp = (int)Option::get('crm', self::INSTALL_DATE_OPTION, 0);
		if ($installTimestamp <= 0)
		{
			return self::$readOnlyCache = true;
		}

		$deadlineTimestamp = $installTimestamp + (self::SUNSET_PERIOD_DAYS * 86400);
		if (time() >= $deadlineTimestamp)
		{
			self::cleanupAfterTransitionToReadOnly();

			return self::$readOnlyCache = true;
		}

		return self::$readOnlyCache = false;
	}

	private function getCurrentPhase(): string
	{
		$installTimestamp = (int)Option::get('crm', self::INSTALL_DATE_OPTION, 0);
		if ($installTimestamp <= 0)
		{
			return self::PHASE_READONLY;
		}

		$deadlineTimestamp = $installTimestamp + (self::SUNSET_PERIOD_DAYS * 86400);

		return time() >= $deadlineTimestamp ? self::PHASE_READONLY : self::PHASE_WARNING;
	}

	private function getDaysLeftUntilDisable(): int
	{
		$installTimestamp = (int)Option::get('crm', self::INSTALL_DATE_OPTION, 0);
		if ($installTimestamp <= 0)
		{
			return 0;
		}

		$deadlineTimestamp = $installTimestamp + (self::SUNSET_PERIOD_DAYS * 86400);
		$secondsLeft = $deadlineTimestamp - time();
		if ($secondsLeft < 86400)
		{
			return 0;
		}

		return (int)ceil($secondsLeft / 86400);
	}

	private static function cleanupAfterTransitionToReadOnly(): void
	{
		Option::delete('crm', ['name' => self::INSTALL_DATE_OPTION]);
		\CUserOptions::DeleteOptionsByName('crm', self::WARNING_LAST_SHOWN_FIELD);
	}
}
