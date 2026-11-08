<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Integration\Socialnetwork;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

/**
 * Livefeed side of a full report: the entry the report was published to and its address on the portal.
 */
class FullReportLivefeedService
{
	private const ENTRY_EVENT_ID = 'report';
	private const ENTRY_PATH_TEMPLATE = 'company/personal/log/#log_id#/';

	/**
	 * Path of the livefeed entry the report was published to, or null when the report has no entry,
	 * when the viewer may not read it, or when the viewer is not the current user.
	 * Socialnetwork resolves read rights against the global user object rather than against the passed
	 * viewer, so the viewer is only trusted while it matches the current user. Every refusal answers the
	 * same and gives away nothing about foreign reports.
	 */
	public function getEntryPath(int $reportId, int $viewerId): ?string
	{
		if (
			$reportId <= 0
			|| $viewerId <= 0
			|| (int)CurrentUser::get()->getId() !== $viewerId
			|| !Loader::includeModule('socialnetwork')
		)
		{
			return null;
		}

		// Not cached: the entry is read by the selective SOURCE_ID index and limited to a single row, while
		// the rights check refreshes access codes and adds an EXISTS subquery a cached path could not skip.
		$entry = \CSocNetLog::GetList(
			[],
			[
				'EVENT_ID' => self::ENTRY_EVENT_ID,
				'SOURCE_ID' => $reportId,
			],
			false,
			['nTopCount' => 1],
			['ID', 'SITE_ID'],
			[
				'CHECK_RIGHTS' => 'Y',
				'USER_ID' => $viewerId,
			],
		)->Fetch();

		$logId = (int)($entry['ID'] ?? 0);
		if ($logId <= 0)
		{
			return null;
		}

		return $this->buildEntryPath($logId, (string)($entry['SITE_ID'] ?? ''));
	}

	private function buildEntryPath(int $logId, string $siteId): string
	{
		$pathTemplate = (string)Option::get(
			'socialnetwork',
			'log_entry_page',
			'',
			$siteId === '' ? false : $siteId,
		);

		if ($pathTemplate === '')
		{
			// The portal is free to live in a subdirectory, so the default is prefixed with the site directory
			// exactly as the neighbouring livefeed consumers do (im/classes/general/im_event.php). The
			// directory is taken from the site of the entry rather than from the site of the request: on a
			// portal of several sites those differ, and the address belongs to the entry.
			$pathTemplate = self::siteDirectory($siteId) . self::ENTRY_PATH_TEMPLATE;
		}

		return \CComponentEngine::makePathFromTemplate($pathTemplate, ['log_id' => $logId]);
	}

	/**
	 * Directory of the site the entry belongs to, and the directory of the request when the entry names no
	 * site of its own — that is the only case left where nothing better is known.
	 */
	private function siteDirectory(string $siteId): string
	{
		if ($siteId === '')
		{
			return SITE_DIR;
		}

		$site = \CSite::GetArrayByID($siteId);
		$directory = is_array($site) ? (string)($site['DIR'] ?? '') : '';

		return $directory === '' ? SITE_DIR : $directory;
	}
}
