<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

final class OpenFailureScreen
{
	private const PHRASE_PREFIX = 'DISK_FILE_EDITOR_VIBEOFFICE_';

	public static function forKind(string $kind, ?string $failureKind = null, bool $isEdit = false): array
	{
		$screen = match ($kind)
		{
			'large-file' => self::screen($kind, 'LARGE_FILE', true, true, ['download']),
			'filetype-not-editable' => self::screen($kind, 'FILETYPE_NOT_EDITABLE', true, false, ['view']),
			'unsupported-filetype' => self::screen($kind, 'UNSUPPORTED_FILETYPE', true),
			'open-413-other' => self::screen($kind, 'OPEN_413_OTHER', false, false, ['return']),
			'open-422-other' => self::screen($kind, 'OPEN_422_OTHER', false, false, ['return']),
			'no-capacity' => self::screen(
				$kind,
				$isEdit ? 'NO_CAPACITY_EDIT' : 'NO_CAPACITY_VIEW',
				false,
				false,
				['retry', 'return'],
			),
			'limit-exceeded' => self::screen($kind, 'LIMIT', false),
			'not-found' => self::screen($kind, 'CLOUD_ERROR_COMMON', false),
			default => self::screen('cloud-error', 'CLOUD_ERROR_COMMON', false),
		};

		if ($screen['kind'] === 'cloud-error')
		{
			if ($failureKind === 'save_refused')
			{
				$screen['title'] = self::PHRASE_PREFIX . 'SAVE_REFUSED_TITLE';
				$screen['description'] = self::PHRASE_PREFIX . 'SAVE_REFUSED_DESCR';
				$screen['reasonLabel'] = self::PHRASE_PREFIX . 'SAVE_REFUSED_REASON';
			}
			elseif ($failureKind === 'open_operation_expired')
			{
				$screen['title'] = self::PHRASE_PREFIX . 'OPEN_EXPIRED_TITLE';
				$screen['description'] = self::PHRASE_PREFIX . 'OPEN_EXPIRED_DESCR';
				$screen['actions'] = ['retry'];
			}
		}

		return $screen;
	}

	private static function screen(
		string $kind,
		string $phrase,
		bool $fileName,
		bool $fileSize = false,
		array $actions = [],
	): array
	{
		return [
			'kind' => $kind,
			'title' => self::PHRASE_PREFIX . $phrase . '_TITLE',
			'description' => self::PHRASE_PREFIX . $phrase . ($phrase === 'LIMIT' ? '_DESC' : '_DESCR'),
			'fileName' => $fileName,
			'fileSize' => $fileSize,
			'actions' => $actions,
		];
	}
}
