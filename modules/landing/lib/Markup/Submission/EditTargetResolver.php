<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup\Submission;

use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Internals\LandingTable;
use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Landing\Rights;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The single resolver of the edit target of the markup tools. The chat binding of the calling user
 * names the site, the site holds exactly one live page, the user may edit it and the page is a custom
 * markup page. A refusal answers with its code and writes nothing; the page identifier never comes
 * from the caller.
 */
final class EditTargetResolver
{
	public const ERROR_BINDING_INVALID = 'MARKUP_EDIT_BINDING_INVALID';
	public const ERROR_SITE_NOT_SINGLE_PAGE = 'MARKUP_SITE_NOT_SINGLE_PAGE';
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_NOT_MARKUP_PAGE = 'NOT_MARKUP_PAGE';

	public const ACCESS_DENIED_MESSAGE = 'Access denied. You do not have permission to edit this page.';

	private readonly AiSiteBindingService $bindingService;
	private readonly PackageSubmissionService $submissionService;

	public function __construct(
		?AiSiteBindingService $bindingService = null,
		?PackageSubmissionService $submissionService = null,
	)
	{
		$this->bindingService = $bindingService ?? new AiSiteBindingService();
		$this->submissionService = $submissionService ?? new PackageSubmissionService();
	}

	/**
	 * @return Result Success data: ['target' => SubmissionTarget] of the update mode.
	 */
	public function resolve(int $bindingId, int $userId): Result
	{
		$result = new Result();

		$binding = $this->bindingService->getByIdForUser($bindingId, $userId);
		$siteId = (int)($binding['SITE_ID'] ?? 0);
		if ($siteId <= 0)
		{
			return $result->addError(new Error(
				'The binding was not found, belongs to another user or holds no site.',
				self::ERROR_BINDING_INVALID,
			));
		}

		// the pages of a site in the recycle bin stay live, the site itself is gone for the editor
		if (!$this->isLiveSite($siteId))
		{
			return $result->addError(new Error(
				'The site of the binding was deleted or is in the recycle bin.',
				self::ERROR_BINDING_INVALID,
			));
		}

		$pageIds = $this->findLivePageIds($siteId);
		if (count($pageIds) !== 1)
		{
			return $result->addError(new Error(
				'The site of the binding must hold exactly one live page.',
				self::ERROR_SITE_NOT_SINGLE_PAGE,
			));
		}
		$pageId = $pageIds[0];

		if (!Rights::hasAccessForLanding($pageId, Rights::ACCESS_TYPES['edit']))
		{
			return $result->addError(new Error(self::ACCESS_DENIED_MESSAGE, self::ERROR_ACCESS_DENIED));
		}

		$target = $this->submissionService->resolveUpdateTarget($pageId);
		if ($target === null)
		{
			return $result->addError(new Error(
				'The target page has no custom markup block. Regular AI pages are edited by the change_ai_site line.',
				self::ERROR_NOT_MARKUP_PAGE,
			));
		}

		return $result->setData(['target' => $target]);
	}

	private function isLiveSite(int $siteId): bool
	{
		$row = SiteTable::query()
			->setSelect(['ID'])
			->where('ID', $siteId)
			->where('DELETED', 'N')
			->setLimit(1)
			->fetch()
		;

		return is_array($row);
	}

	/**
	 * @return list<int>
	 */
	private function findLivePageIds(int $siteId): array
	{
		$rows = LandingTable::query()
			->setSelect(['ID'])
			->where('SITE_ID', $siteId)
			->where('DELETED', 'N')
			->where('FOLDER', 'N')
			->setLimit(2)
			->exec()
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}
}
