<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\Update\LateServiceBindingStepper;
use Bitrix\Main\Config\Option;

class MailServiceInstaller
{
	private const SERVICES_VERSION = 3;
	private const MODULE_NAME = 'mail';
	private const OPTION_NAME = 'services_version';

	public static function getMailhostService(): array
	{
		return [
			'NAME' => 'mailhost',
			'SERVICE_TYPE' => 'imap',
			'FLAGS' => \Bitrix\Mail\MailServicesTable::FLAG_EXTERNAL_CREDENTIALS,
		];
	}

	/**
	 * Install mail services.
	 * @param $siteId
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function installServices($siteId)
	{
		$mailServices = array(
			'gmail' => array(
				'SERVER' => 'imap.gmail.com',
				'PORT' => 993,
				'ENCRYPTION' => 'Y',
				'LINK' => 'https://mail.google.com/',
				'SMTP_SERVER' => 'smtp.gmail.com',
				'SMTP_PORT' => 465,
				'SMTP_ENCRYPTION' => 'Y',
				'SMTP_LOGIN_AS_IMAP' => 'Y',
				'SMTP_PASSWORD_AS_IMAP' => 'Y',
				'UPLOAD_OUTGOING' => 'N',
			),
			'icloud' => array(
				'SERVER' => 'imap.mail.me.com',
				'PORT' => 993,
				'ENCRYPTION' => 'Y',
				'LINK' => 'https://www.icloud.com/#mail',
				'SMTP_SERVER' => 'smtp.mail.me.com',
				'SMTP_PORT' => 587,
				'SMTP_ENCRYPTION' => 'N',
				'SMTP_LOGIN_AS_IMAP' => 'Y',
				'SMTP_PASSWORD_AS_IMAP' => 'Y',
				'UPLOAD_OUTGOING' => 'Y',
			),
			'outlook.com' => array(
				'SERVER' => 'imap-mail.outlook.com',
				'PORT' => 993,
				'ENCRYPTION' => 'Y',
				'LINK' => 'https://www.outlook.com/owa',
				'SMTP_SERVER' => 'smtp-mail.outlook.com',
				'SMTP_PORT' => 587,
				'SMTP_ENCRYPTION' => 'N',
				'SMTP_LOGIN_AS_IMAP' => 'Y',
				'SMTP_PASSWORD_AS_IMAP' => 'Y',
				'UPLOAD_OUTGOING' => 'Y',
			),
			'office365' => array(
				'SERVER' => 'outlook.office365.com',
				'PORT' => 993,
				'ENCRYPTION' => 'Y',
				'LINK' => 'http://mail.office365.com/',
				'SMTP_SERVER' => 'smtp.office365.com',
				'SMTP_PORT' => 587,
				'SMTP_ENCRYPTION' => 'N',
				'SMTP_LOGIN_AS_IMAP' => 'Y',
				'SMTP_PASSWORD_AS_IMAP' => 'Y',
				'UPLOAD_OUTGOING' => 'N',
			),
			'yahoo' => array(
				'SERVER' => 'imap.mail.yahoo.com',
				'PORT' => 993,
				'ENCRYPTION' => 'Y',
				'LINK' => 'http://mail.yahoo.com/',
				'SMTP_SERVER' => 'smtp.mail.yahoo.com',
				'SMTP_PORT' => 465,
				'SMTP_ENCRYPTION' => 'Y',
				'SMTP_LOGIN_AS_IMAP' => 'Y',
				'SMTP_PASSWORD_AS_IMAP' => 'Y',
				'UPLOAD_OUTGOING' => 'N',
			),
			'aol' => array(
				'SERVER' => 'imap.aol.com',
				'PORT' => 993,
				'ENCRYPTION' => 'Y',
				'LINK' => 'http://mail.aol.com/',
				'SMTP_SERVER' => 'smtp.aol.com',
				'SMTP_PORT' => 465,
				'SMTP_ENCRYPTION' => 'Y',
				'SMTP_LOGIN_AS_IMAP' => 'Y',
				'SMTP_PASSWORD_AS_IMAP' => 'Y',
				'UPLOAD_OUTGOING' => 'Y',
			),
			'yandex' => array(
				'SERVER' => 'imap.yandex.ru',
				'PORT' => 993,
				'ENCRYPTION' => 'Y',
				'LINK' => 'https://mail.yandex.ru/',
				'SMTP_SERVER' => 'smtp.yandex.ru',
				'SMTP_PORT' => 465,
				'SMTP_ENCRYPTION' => 'Y',
				'SMTP_LOGIN_AS_IMAP' => 'Y',
				'SMTP_PASSWORD_AS_IMAP' => 'Y',
				'UPLOAD_OUTGOING' => 'Y',
			),
			'mail.ru' => array(
				'SERVER' => 'imap.mail.ru',
				'PORT' => 993,
				'ENCRYPTION' => 'Y',
				'LINK' => 'http://e.mail.ru/',
				'SMTP_SERVER' => 'smtp.mail.ru',
				'SMTP_PORT' => 465,
				'SMTP_ENCRYPTION' => 'Y',
				'SMTP_LOGIN_AS_IMAP' => 'Y',
				'SMTP_PASSWORD_AS_IMAP' => 'Y',
				'UPLOAD_OUTGOING' => 'Y',
			),
			'exchange' => array(),
			'other' => array(),
			'exchangeOnline' => array(
				'SERVER' => 'outlook.office365.com',
				'PORT' => 993,
				'ENCRYPTION' => 'Y',
				'LINK' => 'https://mail.office365.com/',
				'SMTP_SERVER' => 'smtp.office365.com',
				'SMTP_PORT' => 587,
				'SMTP_ENCRYPTION' => 'N',
				'SMTP_LOGIN_AS_IMAP' => 'Y',
				'SMTP_PASSWORD_AS_IMAP' => 'Y',
				'UPLOAD_OUTGOING' => 'N',
			),
			// Bitrix24 mail hosting. The row carries no connection data on purpose: every such field of
			// a service is substituted for the value of the mailbox when the mailbox is saved, while
			// the credentials here are issued per mailbox and arrive with the handover kit - a port
			// written into the row would be put over the issued one by a plain rename of the mailbox.
			// The empty row is not offered as the "empty service" a deleted one re-hangs its mailboxes
			// onto either: that pick skips a service whose credentials come from outside by the mark
			// itself, so the row needs no value of its own to stay out of it.
			'mailhost' => self::getMailhostService(),
		);

		// mailhost stands in every zone: a mailbox can move to our hosting from a portal of any of them.
		// Its sort is past the last tile and the same everywhere - the showcase never lists the service,
		// so the number orders nothing.
		$mailServicesByLang = array(
			'ru' => array(
				100  => 'gmail',
				200  => 'outlook.com',
				300  => 'icloud',
				400  => 'office365',
				550  => 'exchangeOnline',
				600  => 'yahoo',
				700  => 'aol',
				800  => 'yandex',
				900  => 'mail.ru',
				1000 => 'other',
				1100 => 'mailhost',
			),
			'ua' => array(
				100  => 'gmail',
				200  => 'outlook.com',
				300  => 'icloud',
				400  => 'office365',
				550  => 'exchangeOnline',
				600  => 'yahoo',
				700  => 'aol',
				800  => 'other',
				1100 => 'mailhost',
			),
			'en' => array(
				100 => 'gmail',
				200 => 'outlook.com',
				300 => 'icloud',
				400 => 'office365',
				550 => 'exchangeOnline',
				600 => 'yahoo',
				700 => 'aol',
				800 => 'other',
				1100 => 'mailhost'
			),
			'de' => array(
				100 => 'gmail',
				200 => 'outlook.com',
				300 => 'icloud',
				400 => 'office365',
				550 => 'exchangeOnline',
				600 => 'yahoo',
				700 => 'aol',
				800 => 'other',
				1100 => 'mailhost'
			)
		);

		$site = \Bitrix\Main\SiteTable::getList(array('filter' => ["=LID" => $siteId]))
			->fetch();

		if (!$site)
			return;

		if (\CModule::IncludeModule('extranet') && \CExtranet::IsExtranetSite($site['LID']))
			return;

		$portalZone = \Bitrix\Main\Loader::includeModule('bitrix24')
			? \CBitrix24::getPortalZone()
			: $site['LANGUAGE_ID']
		;

		$portalZone = $portalZone ?: LANGUAGE_ID;
		$portalZone = !in_array($portalZone, ['ru', 'kz', 'by']) ? $portalZone : 'ru';

		$mailServicesList = isset($mailServicesByLang[$portalZone])
			? $mailServicesByLang[$portalZone]
			: $mailServicesByLang['en'];

		$added = false;
		foreach ($mailServicesList as $serviceSort => $serviceName)
		{
			$exists = \Bitrix\Mail\MailServicesTable::getRow([
				'filter' => [
					'=SITE_ID' => $site['LID'],
					'=NAME' => $serviceName,
					'=SERVICE_TYPE' => 'imap',
				]
			]);

			if ($exists)
			{
				continue;
			}

			$serviceSettings = $mailServices[$serviceName];

			$serviceSettings['SITE_ID']      = $site['LID'];
			$serviceSettings['ACTIVE']       = 'Y';
			$serviceSettings['SERVICE_TYPE'] = 'imap';
			$serviceSettings['NAME']         = $serviceName;
			$serviceSettings['SORT']         = $serviceSort;

			if (\Bitrix\Mail\MailServicesTable::add($serviceSettings)->isSuccess())
			{
				$added = true;
			}
		}

		if (!$added)
		{
			return;
		}

		/*
			The directory of the site gained a row. A mailbox handed over to one of its services from
			outside carries the number of that service, and a hand-over that happened before the row
			existed carries a zero instead: the kit names the service, and a name of a row that is not
			there resolves to nothing. Such a mailbox is owed its number now - and it is asked for in
			the background, not here: this runs on the page that opens the mail settings, and the
			mailboxes owed a number are as many as the hand-overs the portal accumulated.
		*/
		LateServiceBindingStepper::request();
	}

	/**
	 * Check services installation and update if not installed.
	 * @param $siteId
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function checkInstallComplete($siteId)
	{
		$version = (int)Option::get(self::MODULE_NAME, self::OPTION_NAME, 0, $siteId);

		if ($version === self::SERVICES_VERSION)
		{
			return;
		}
		Option::set(self::MODULE_NAME, self::OPTION_NAME, self::SERVICES_VERSION, $siteId);

		self::installServices($siteId);
	}
}