<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup;

use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Removes the site a failed markup creation has left behind (K6): the site, its page, the carrier block
 * and the file bindings of the block. The files themselves go by the reference counter of the bindings,
 * the site does not go through the recycle bin - the user has never seen it as a site of their own.
 *
 * Only a site this very generation has created is removed: the creation journal of the generation must
 * name it. Any other site - a seeded one, a site of another generation - is left as it is.
 */
class PartialSiteRemover
{
	public const ERROR_REMOVAL_FAILED = 'MARKUP_SITE_REMOVAL_FAILED';

	/**
	 * @return Result Success data: ['removed' => bool]; false when there was nothing of this generation
	 *     to remove. An error means the site of the generation is still there.
	 */
	public function remove(int $siteId, int $generationId): Result
	{
		$result = new Result();
		if ($siteId <= 0 || $generationId <= 0)
		{
			return $result->setData(['removed' => false]);
		}

		try
		{
			if ($this->readCreatedSiteId($generationId) !== $siteId || !$this->siteExists($siteId))
			{
				return $result->setData(['removed' => false]);
			}

			$deleted = $this->deleteSite($siteId);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error($exception->getMessage(), self::ERROR_REMOVAL_FAILED));
		}

		if (!$deleted->isSuccess())
		{
			foreach ($deleted->getErrors() as $error)
			{
				$result->addError(new Error($error->getMessage(), self::ERROR_REMOVAL_FAILED));
			}

			return $result;
		}

		return $result->setData(['removed' => true]);
	}

	/**
	 * The site the generation has recorded as created by itself, 0 when it has created none.
	 */
	protected function readCreatedSiteId(int $generationId): int
	{
		$row = GenerationsTable::query()
			->setSelect(['DATA'])
			->where('ID', '=', $generationId)
			->fetch()
		;
		$data = is_array($row) && is_array($row['DATA'] ?? null) ? $row['DATA'] : [];
		$journal = $data[CreateAiSiteState::CREATION_JOURNAL_KEY] ?? null;

		return is_array($journal) ? max(0, (int)($journal['siteId'] ?? 0)) : 0;
	}

	protected function siteExists(int $siteId): bool
	{
		$row = SiteTable::getList([
			'select' => ['ID'],
			'filter' => [
				'ID' => $siteId,
				'=DELETED' => ['Y', 'N'],
				'CHECK_PERMISSIONS' => 'N',
			],
		])->fetch();

		return is_array($row);
	}

	protected function deleteSite(int $siteId): Result
	{
		$rightsWereOn = Rights::isGlobalOn();
		Rights::setGlobalOff();
		try
		{
			return Site::delete($siteId, true);
		}
		finally
		{
			if ($rightsWereOn)
			{
				Rights::setGlobalOn();
			}
		}
	}
}
