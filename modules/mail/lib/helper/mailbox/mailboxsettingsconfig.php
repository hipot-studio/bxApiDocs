<?php

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;

final class MailboxSettingsConfig
{
	private const DEFAULT_CRM_SOURCE = 'EMAIL';
	private const FALLBACK_CRM_SOURCE = 'OTHER';
	private const CRM_ENTITY_LEAD = 'LEAD';
	private const CRM_ENTITY_CONTACT = 'CONTACT';
	private const MAIL_SYNC_INTERVALS = [1, 7, 30, 60, 90, -1];
	private const CRM_SYNC_INTERVALS = [7, 30, -1];

	/**
	 * @return array{
	 *     mailSyncIntervals: array<array{value: int, label: string}>,
	 *     crmSyncIntervals: array<array{value: int, label: string}>,
	 *     crmEntities: array<array{value: string, label: string}>,
	 *     crmSources: array<array{value: string, label: string}>,
	 *     defaultCrmSource: string,
	 *     defaults: array{
	 *         mailSyncEnabled: bool,
	 *         messageMaxAge: int,
	 *         crmEnabled: bool,
	 *         crmSyncEnabled: bool,
	 *         crmSyncPeriod: int,
	 *         crmAssignKnownClientEmails: bool,
	 *         crmIncomingCreate: bool,
	 *         crmIncomingEntity: string,
	 *         crmOutgoingCreate: bool,
	 *         crmOutgoingEntity: string,
	 *         crmVcf: bool,
	 *         crmSource: string,
	 *         calendarAutoAddEvents: bool,
	 *     },
	 * }
	 * @throws LoaderException
	 */
	public static function getConfig(): array
	{
		$crmSourcesMap = self::getCrmSourcesMap();
		$defaults = self::getDefaultSettings($crmSourcesMap);

		return [
			'mailSyncIntervals' => self::getIntervalsWithLabels(self::getMailSyncIntervals()),
			'crmSyncIntervals' => self::getIntervalsWithLabels(self::CRM_SYNC_INTERVALS),
			'crmEntities' => self::getCrmEntitiesWithLabels(),
			'crmSources' => self::normalizeOptions($crmSourcesMap),
			'defaultCrmSource' => $defaults['crmSource'],
			'defaults' => $defaults,
		];
	}

	/**
	 * @return array{
	 *     mailSyncIntervals: array<array{value: int, label: string}>,
	 *     crmSyncIntervals: array<array{value: int, label: string}>,
	 *     crmEntities: array<array{value: string, label: string}>,
	 *     crmSources: array<array{value: string, label: string}>,
	 *     defaultCrmSource: string,
	 *     defaults: array{
	 *         mailSyncEnabled: bool,
	 *         messageMaxAge: int,
	 *         crmEnabled: bool,
	 *         crmSyncEnabled: bool,
	 *         crmSyncPeriod: int,
	 *         crmAssignKnownClientEmails: bool,
	 *         crmIncomingCreate: bool,
	 *         crmIncomingEntity: string,
	 *         crmOutgoingCreate: bool,
	 *         crmOutgoingEntity: string,
	 *         crmVcf: bool,
	 *         crmSource: string,
	 *         calendarAutoAddEvents: bool,
	 *     },
	 *     crmAvailable: bool,
	 *     canEditCrmIntegration: bool,
	 * }
	 * @throws LoaderException
	 */
	public static function getClientConfig(): array
	{
		$config = self::getConfig();
		$crmAvailable = Feature::isCrmAvailable() && MailboxAccess::hasCurrentUserAccessToViewMailboxIntegrationCrm();

		return array_merge($config, [
			'crmAvailable' => $crmAvailable,
			'canEditCrmIntegration' => $crmAvailable
				&& MailboxAccess::hasCurrentUserAccessToEditMailboxIntegrationCrm(),
		]);
	}

	/**
	 * @return array<array{value: string, label: string}>
	 * @throws LoaderException
	 */
	public static function getCrmEntitiesWithLabels(): array
	{
		$map = self::getCrmEntitiesMap();
		$result = [];

		foreach ($map as $value => $label)
		{
			$result[] = [
				'value' => $value,
				'label' => $label,
			];
		}

		return $result;
	}

	/**
	 * @return array<string, string>
	 * @throws LoaderException
	 */
	public static function getCrmEntitiesMap(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [
				self::CRM_ENTITY_LEAD => Loc::getMessage('MAIL_MAILBOX_SETTINGS_CRM_ENTITY_LEAD') ?? self::CRM_ENTITY_LEAD,
				self::CRM_ENTITY_CONTACT => Loc::getMessage('MAIL_MAILBOX_SETTINGS_CRM_ENTITY_CONTACT') ?? self::CRM_ENTITY_CONTACT,
			];
		}

		return [
			self::CRM_ENTITY_LEAD => \CCrmOwnerType::getDescription(\CCrmOwnerType::Lead),
			self::CRM_ENTITY_CONTACT => \CCrmOwnerType::getDescription(\CCrmOwnerType::Contact),
		];
	}

	/**
	 * @return array<string, string>
	 * @throws LoaderException
	 */
	public static function getCrmSourcesMap(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		return \CCrmStatus::getStatusList('SOURCE');
	}

	/**
	 * @param array<string, string>|null $crmSourcesMap
	 * @throws LoaderException
	 */
	public static function getDefaultCrmSource(?array $crmSourcesMap = null): string
	{
		$crmSourcesMap ??= self::getCrmSourcesMap();

		reset($crmSourcesMap);
		$defaultCrmSource = (string)(key($crmSourcesMap) ?: '');

		if (array_key_exists(self::DEFAULT_CRM_SOURCE, $crmSourcesMap))
		{
			return self::DEFAULT_CRM_SOURCE;
		}

		if (array_key_exists(self::FALLBACK_CRM_SOURCE, $crmSourcesMap))
		{
			return self::FALLBACK_CRM_SOURCE;
		}

		return $defaultCrmSource;
	}

	public static function getDefaultSettings(?array $crmSourcesMap = null): array
	{
		return self::getDefaults(self::getDefaultCrmSource($crmSourcesMap));
	}

	/**
	 * @return array<int>
	 */
	private static function getMailSyncIntervals(): array
	{
		if (Feature::isUnlimitedMailSyncPeriodAvailable())
		{
			return self::MAIL_SYNC_INTERVALS;
		}

		return array_values(array_filter(
			self::MAIL_SYNC_INTERVALS,
			static fn(int $days) => $days >= 0,
		));
	}

	/**
	 * @param array<int> $intervals
	 * @return array<array{value: int, label: string}>
	 */
	private static function getIntervalsWithLabels(array $intervals): array
	{
		return array_map(
			static fn(int $days) => [
				'value' => $days,
				'label' => self::getPeriodLabel($days),
			],
			$intervals,
		);
	}

	private static function getPeriodLabel(int $days): string
	{
		if ($days < 0)
		{
			return Loc::getMessage('MAIL_MAILBOX_SETTINGS_PERIOD_ALL_TIME') ?? '';
		}

		return Loc::getMessage('MAIL_MAILBOX_SETTINGS_PERIOD_' . $days)
			?? Loc::getMessage('MAIL_MAILBOX_SETTINGS_PERIOD_DAYS', ['#DAYS#' => $days])
			?? (string)$days;
	}

	/**
	 * @param array<string, string> $map
	 * @return array<array{value: string, label: string}>
	 */
	private static function normalizeOptions(array $map): array
	{
		$options = [];

		foreach ($map as $value => $label)
		{
			$options[] = [
				'value' => (string)$value,
				'label' => (string)$label,
			];
		}

		return $options;
	}

	private static function getDefaults(string $defaultCrmSource): array
	{
		return [
			'mailSyncEnabled' => true,
			'messageMaxAge' => 7,
			'crmEnabled' => false,
			'crmSyncEnabled' => true,
			'crmSyncPeriod' => 7,
			'crmAssignKnownClientEmails' => true,
			'crmIncomingCreate' => true,
			'crmIncomingEntity' => self::CRM_ENTITY_LEAD,
			'crmOutgoingCreate' => true,
			'crmOutgoingEntity' => self::CRM_ENTITY_CONTACT,
			'crmVcf' => true,
			'crmSource' => $defaultCrmSource,
			'calendarAutoAddEvents' => true,
		];
	}
}
