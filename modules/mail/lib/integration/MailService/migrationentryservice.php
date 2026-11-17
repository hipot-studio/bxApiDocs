<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\MailService\Public\Command\GrantMigrationConsentCommand;
use Bitrix\MailService\Public\Service\Sso\MailhostAdminEntryService;

class MigrationEntryService
{
	public const ERROR_UNAVAILABLE = 'migration_entry_unavailable';

	private const SERVICE_MODULE_ID = 'mailservice';

	/**
	 * @return Result Data: domain, consentGranted, url and, for an expected refusal, error.
	 */
	public function enter(string $domain, int $adminId, string $textVersion, string $displayName = ''): Result
	{
		if (!$this->isAvailable())
		{
			return $this->refusal($domain, false, self::ERROR_UNAVAILABLE);
		}

		$consent = (new GrantMigrationConsentCommand())->execute($domain, $adminId, $textVersion);
		if (!$consent->isSuccess())
		{
			return $this->refusal($domain, false, $this->firstErrorCode($consent));
		}

		$entry = (new MailhostAdminEntryService())->issueFor($adminId, $displayName);
		if (!$entry->isSuccess())
		{
			return $this->refusal($domain, true, $this->firstErrorCode($entry));
		}

		return (new Result())->setData([
			'domain' => $domain,
			'consentGranted' => true,
			'url' => (string)($entry->getData()[MailhostAdminEntryService::URL_DATA_KEY] ?? ''),
		]);
	}

	/**
	 * Gives the consent for each of the domains, the way {@see self::enter()} gives it for one, and does not
	 * stop at a domain that refused: the panel comes back for a domain left without a consent.
	 *
	 * @param list<string> $domains
	 * @return list<string> The domains the consent was given for.
	 */
	public function grantConsents(array $domains, int $adminId, string $textVersion): array
	{
		if ($domains === [] || !$this->isAvailable())
		{
			return [];
		}

		$granted = [];
		foreach (array_unique($domains) as $domain)
		{
			if ((new GrantMigrationConsentCommand())->execute($domain, $adminId, $textVersion)->isSuccess())
			{
				$granted[] = $domain;
			}
		}

		return $granted;
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule(self::SERVICE_MODULE_ID)
			&& class_exists(GrantMigrationConsentCommand::class)
			&& class_exists(MailhostAdminEntryService::class);
	}

	private function refusal(string $domain, bool $consentGranted, string $error): Result
	{
		return (new Result())->setData([
			'domain' => $domain,
			'consentGranted' => $consentGranted,
			'url' => null,
			'error' => $error,
		]);
	}

	private function firstErrorCode(Result $result): string
	{
		return (string)($result->getErrors()[0]->getCode() ?? self::ERROR_UNAVAILABLE);
	}
}
