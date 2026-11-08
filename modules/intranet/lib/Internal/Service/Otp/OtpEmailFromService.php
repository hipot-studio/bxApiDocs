<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Web\Uri;

final class OtpEmailFromService
{
	public const OPTION_NAME = 'otp_email_from';

	private const CONFIRMATION_EVENT_NAME = 'USER_OTP_EMAIL_CONFIRM';

	public static function normalize(?string $rawValue): ?string
	{
		if ($rawValue === null)
		{
			return null;
		}

		return (new Address($rawValue))->getEmail();
	}

	public static function getConfigured(): ?string
	{
		return self::normalize((string)Option::get('intranet', self::OPTION_NAME, ''));
	}

	public static function initialize(): ?string
	{
		$configured = self::getConfigured();
		if ($configured !== null)
		{
			return $configured;
		}

		$candidate = IsModuleInstalled('bitrix24')
			? self::findCloudCandidate()
			: self::findBoxCandidate()
		;

		$normalized = self::normalize($candidate);
		if ($normalized === null)
		{
			return null;
		}

		Option::set('intranet', self::OPTION_NAME, $normalized);

		return $normalized;
	}

	private static function findCloudCandidate(): ?string
	{
		$portalDomain = (string)Option::get('main', 'server_name', '');
		if ($portalDomain === '')
		{
			$portalDomain = (string)Context::getCurrent()->getRequest()->getHttpHost();
		}

		if ($portalDomain === '')
		{
			return null;
		}

		$host = (new Uri('http://' . $portalDomain))->getHost();

		return $host === '' ? null : 'no-reply@' . $host;
	}

	private static function findBoxCandidate(): ?string
	{
		$templates = \CEventMessage::GetList(
			'id',
			'asc',
			['EVENT_NAME' => self::CONFIRMATION_EVENT_NAME],
		);

		$firstTemplate = null;
		while ($template = $templates->Fetch())
		{
			$firstTemplate ??= $template;

			$emailFrom = (string)$template['EMAIL_FROM'];
			if (self::normalize($emailFrom) !== null)
			{
				return $emailFrom;
			}
		}

		$siteFields = \CEvent::GetSiteFieldsArray(self::resolveSiteId($firstTemplate));

		return is_array($siteFields) ? (string)($siteFields['DEFAULT_EMAIL_FROM'] ?? '') : null;
	}

	private static function resolveSiteId(?array $template): string|false
	{
		if ($template === null)
		{
			return \CSite::GetDefSite() ?: false;
		}

		$siteIds = [];
		$templateSites = \CEventMessage::GetSite((int)$template['ID']);
		while ($templateSite = $templateSites->Fetch())
		{
			$siteIds[] = (string)$templateSite['LID'];
		}

		sort($siteIds, SORT_STRING);

		return $siteIds[0] ?? false;
	}
}
