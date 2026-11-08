<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Mapper;

use Bitrix\Rest\Internal\Model\AppStatus;
use Bitrix\Rest\Public\Provider\Dto\ApplicationData;
use Bitrix\Rest\Public\Provider\Dto\ApplicationLanguageData;
use Bitrix\Rest\Public\Provider\Dto\ApplicationStatus;

final class ApplicationDataMapper
{
	public const ORM_FIELD_ID = 'ID';

	/**
	 * @param array<string, mixed> $row
	 * @param ?list<array<string, mixed>> $languageRows
	 */
	public function convertFromRow(array $row, ?array $languageRows = null): ApplicationData
	{
		return new ApplicationData(
			id: self::intOrNull($row[self::ORM_FIELD_ID] ?? null),
			clientId: self::stringOrNull($row['CLIENT_ID'] ?? null),
			code: self::stringOrNull($row['CODE'] ?? null),
			active: self::boolOrNull($row['ACTIVE'] ?? null),
			installed: self::boolOrNull($row['INSTALLED'] ?? null),
			url: self::stringOrNull($row['URL'] ?? null),
			demoUrl: self::stringOrNull($row['URL_DEMO'] ?? null),
			installUrl: self::stringOrNull($row['URL_INSTALL'] ?? null),
			settingsUrl: self::stringOrNull($row['URL_SETTINGS'] ?? null),
			version: self::stringOrNull($row['VERSION'] ?? null),
			scope: self::parseScope((string)($row['SCOPE'] ?? '')),
			status: self::statusOrNull($row['STATUS'] ?? null),
			dateCreate: $row['DATE_CREATE'] ?? null,
			dateInstall: $row['DATE_INSTALL'] ?? null,
			dateFinish: $row['DATE_FINISH'] ?? null,
			isTrialed: self::boolOrNull($row['IS_TRIALED'] ?? null),
			sharedKey: self::stringOrNull($row['SHARED_KEY'] ?? null),
			clientSecret: self::stringOrNull($row['CLIENT_SECRET'] ?? null),
			name: self::stringOrNull($row['APP_NAME'] ?? null),
			access: self::stringOrNull($row['ACCESS'] ?? null),
			applicationToken: self::stringOrNull($row['APPLICATION_TOKEN'] ?? null),
			mobile: self::boolOrNull($row['MOBILE'] ?? null),
			userInstall: self::boolOrNull($row['USER_INSTALL'] ?? null),
			installCompletionMode: self::stringOrNull($row['INSTALL_COMPLETION_MODE'] ?? null),
			languages: $languageRows === null ? null : self::convertLanguageRows($languageRows),
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, ApplicationLanguageData>
	 */
	private static function convertLanguageRows(array $rows): array
	{
		$languages = [];
		foreach ($rows as $row)
		{
			$languageId = (string)($row['LANGUAGE_ID'] ?? '');
			$menuName = $row['MENU_NAME'] ?? null;

			$languages[$languageId] = new ApplicationLanguageData(
				$languageId,
				$menuName === null ? null : (string)$menuName,
			);
		}

		return $languages;
	}

	private static function intOrNull(mixed $raw): ?int
	{
		return $raw === null ? null : (int)$raw;
	}

	private static function stringOrNull(mixed $raw): ?string
	{
		return $raw === null ? null : (string)$raw;
	}

	private static function boolOrNull(mixed $raw): ?bool
	{
		return $raw === null ? null : $raw === 'Y';
	}

	private static function statusOrNull(mixed $raw): ?ApplicationStatus
	{
		return $raw === null ? null : AppStatus::toPublicStatus((string)$raw);
	}

	/**
	 * @return list<string>
	 */
	private static function parseScope(string $raw): array
	{
		return array_values(
			array_filter(explode(',', $raw), static fn(string $scope): bool => $scope !== ''),
		);
	}
}
