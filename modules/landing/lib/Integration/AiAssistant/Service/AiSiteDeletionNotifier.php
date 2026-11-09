<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Domain;
use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Landing\Markup\MarkupLifecycleLog;
use Bitrix\Landing\Rights;
use Bitrix\Main\Loader;

/**
 * «Site deleted» in the chats of a site that is deleted for good, whatever started the deletion: the rows of
 * the site and its title are collected before the deletion (after all its refusal checks), the message is
 * published into the chat of every row after it, then the rows are removed.
 *
 * Only the rows of users who can still read the site get the message: a row of a former editor whose access
 * was revoked is removed silently, so the current title of the site does not reach him.
 *
 * The publication is synchronous: a site has a few rows. The recycle bin cleanup may delete many sites in one
 * hit, so the process counts its messages: over the threshold it reports once, and at the limit it stops
 * publishing and checking rights for the rest of the process - the rows are still removed, the skipped rows go
 * to the journal.
 * Neither method throws: the deletion of the site is never interrupted by its chats.
 */
class AiSiteDeletionNotifier
{
	public const BATCH_REPORT_THRESHOLD = 50;
	public const BATCH_LIMIT = 200;

	public const ERROR_CODE_LIMIT = 'batch_limit';
	public const ERROR_CODE_NO_ACCESS = 'no_access';

	public const EVENT_BATCH = 'notice_batch';
	public const EVENT_LOG_AUDIT_TYPE_BATCH = 'LANDING_AI_SITE_CHAT_NOTICE_BATCH';

	private const SITES_PATH = '/sites/';

	private static ?self $instance = null;

	/** @var array<int, array{rows: list<array>, title: string}> */
	private array $collected = [];
	/** @var array<int, true> Sites found without rows: nothing to tell and nothing to remove. */
	private array $sitesWithoutRows = [];
	private int $publishedCount = 0;
	private int $skippedCount = 0;
	private int $checkedCount = 0;
	private float $publishedSeconds = 0.0;
	private bool $isBatchReported = false;
	private bool $isLimitReported = false;

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function collect(int $siteId): void
	{
		if ($siteId <= 0)
		{
			return;
		}

		unset($this->sitesWithoutRows[$siteId]);
		try
		{
			$rows = $this->createBindingService()->findBySite($siteId);
			if ($rows === [])
			{
				// a site not created by AI, or one nobody has opened a chat for
				$this->sitesWithoutRows[$siteId] = true;
			}
			$rows = $this->filterReadableRows($siteId, $rows);
			if ($rows === [])
			{
				unset($this->collected[$siteId]);

				return;
			}

			$this->collected[$siteId] = [
				'rows' => $rows,
				'title' => $this->resolveSiteTitle($siteId),
			];
		}
		catch (\Throwable $throwable)
		{
			unset($this->collected[$siteId]);
			$this->logFailure($siteId, $throwable);
		}
	}

	public function publish(int $siteId): void
	{
		if ($siteId <= 0)
		{
			return;
		}

		$collected = $this->collected[$siteId] ?? null;
		$hasRows = !isset($this->sitesWithoutRows[$siteId]);
		unset($this->collected[$siteId], $this->sitesWithoutRows[$siteId]);

		try
		{
			if ($collected !== null)
			{
				$this->notifyRows($collected['rows'], $collected['title']);
			}
		}
		catch (\Throwable $throwable)
		{
			$this->logFailure($siteId, $throwable);
		}

		if (!$hasRows)
		{
			return;
		}

		try
		{
			$this->createBindingService()->deleteBySite($siteId);
		}
		catch (\Throwable $throwable)
		{
			$this->logFailure($siteId, $throwable);
		}
	}

	protected function createBindingService(): AiSiteBindingService
	{
		return new AiSiteBindingService();
	}

	/**
	 * Created on every publication: the executor of aiassistant is looked up on each notice.
	 */
	protected function createNoticeService(): AiSiteChatNoticeService
	{
		return new AiSiteChatNoticeService();
	}

	protected function createLog(): MarkupLifecycleLog
	{
		return new MarkupLifecycleLog();
	}

	/**
	 * Whether the user of a row can still read the site; the site is in the recycle bin while it is deleted.
	 * Rights are those of that user, whoever deletes the site and whatever checks the process switched off.
	 */
	protected function canUserReadSite(int $userId, int $siteId): bool
	{
		return in_array(
			Rights::ACCESS_TYPES['read'],
			Rights::getUserOperationsForSite($userId, $siteId, true),
			true,
		);
	}

	/**
	 * The file journal is off by default, so the batch is also visible in the event log.
	 */
	protected function addEventLogWarning(array $fields): void
	{
		if (class_exists(\CEventLog::class))
		{
			\CEventLog::Add($fields);
		}
	}

	private function notifyRows(array $rows, string $title): void
	{
		if ($rows === [])
		{
			return;
		}

		// the trigger action that reads the notice is a class of the aiassistant integration
		Loader::includeModule('aiassistant');
		$noticeService = $this->createNoticeService();
		$notice = new AiSiteChatNotice(
			AiSiteChatNotice::TYPE_DELETED,
			$title,
			rtrim(Domain::getHostUrl(), '/') . self::SITES_PATH,
		);

		foreach ($rows as $row)
		{
			if ($this->publishedCount >= self::BATCH_LIMIT)
			{
				$this->skippedCount++;
				$this->logSkippedRow($row, self::ERROR_CODE_LIMIT);
				$this->reportLimit();
				continue;
			}

			$startedAt = microtime(true);
			try
			{
				$noticeService->notifyBinding($row, $notice);
			}
			finally
			{
				$this->publishedSeconds += microtime(true) - $startedAt;
				$this->publishedCount++;
			}
		}

		$this->reportBatch();
	}

	/**
	 * Rights are checked within the limit as well: every check counts, whether the user can read the site or
	 * not, so the rows of former editors do not add queries to the recycle bin cleanup past it. A message needs
	 * a check, so the checks also bound the messages; a row past the limit gets no message anyway.
	 */
	private function filterReadableRows(int $siteId, array $rows): array
	{
		$readable = [];
		foreach ($rows as $row)
		{
			if ($this->checkedCount >= self::BATCH_LIMIT)
			{
				$this->skippedCount++;
				$this->logSkippedRow($row, self::ERROR_CODE_LIMIT);
				$this->reportLimit();

				continue;
			}

			$this->checkedCount++;
			if ($this->canUserReadSite((int)($row['USER_ID'] ?? 0), $siteId))
			{
				$readable[] = $row;
			}
			else
			{
				$this->logSkippedRow($row, self::ERROR_CODE_NO_ACCESS);
			}
		}

		return $readable;
	}

	/**
	 * Reported once per process, when the limit stops the first message; the later ones only go to the journal.
	 */
	private function reportLimit(): void
	{
		if ($this->isLimitReported)
		{
			return;
		}

		$this->isLimitReported = true;
		$this->reportBatch();
		$durationMs = (int)round($this->publishedSeconds * 1000);
		$this->createLog()->write(self::EVENT_BATCH, [
			'count' => $this->publishedCount,
			'limit' => self::BATCH_LIMIT,
			'durationMs' => $durationMs,
		]);

		try
		{
			$this->addEventLogWarning([
				'SEVERITY' => 'WARNING',
				'AUDIT_TYPE_ID' => self::EVENT_LOG_AUDIT_TYPE_BATCH,
				'MODULE_ID' => 'landing',
				'ITEM_ID' => (string)$this->publishedCount,
				'DESCRIPTION' => implode(PHP_EOL, [
					'AI site chat notices of deleted sites reached the limit of one process; the rest are skipped.',
					'COUNT: ' . $this->publishedCount,
					'LIMIT: ' . self::BATCH_LIMIT,
					'DURATION_MS: ' . $durationMs,
				]),
			]);
		}
		catch (\Throwable)
		{
			// the event log must not interrupt the deletion
		}
	}

	private function logSkippedRow(array $row, string $errorCode): void
	{
		try
		{
			$this->createLog()->write(AiSiteChatNoticeService::EVENT_SKIPPED, [
				'type' => AiSiteChatNotice::TYPE_DELETED,
				'siteId' => (int)($row['SITE_ID'] ?? 0),
				'bindingId' => (int)($row['ID'] ?? 0),
				'userId' => (int)($row['USER_ID'] ?? 0),
				'errorCode' => $errorCode,
			]);
		}
		catch (\Throwable)
		{
			// the deletion of the site is never interrupted by its chats
		}
	}

	private function reportBatch(): void
	{
		if ($this->isBatchReported || $this->publishedCount <= self::BATCH_REPORT_THRESHOLD)
		{
			return;
		}

		$this->isBatchReported = true;
		$durationMs = (int)round($this->publishedSeconds * 1000);
		$this->createLog()->write(self::EVENT_BATCH, [
			'count' => $this->publishedCount,
			'durationMs' => $durationMs,
		]);

		try
		{
			$this->addEventLogWarning([
				'SEVERITY' => 'WARNING',
				'AUDIT_TYPE_ID' => self::EVENT_LOG_AUDIT_TYPE_BATCH,
				'MODULE_ID' => 'landing',
				'ITEM_ID' => (string)$this->publishedCount,
				'DESCRIPTION' => implode(PHP_EOL, [
					'AI site chat notices of deleted sites exceeded the threshold within one process.',
					'COUNT: ' . $this->publishedCount,
					'DURATION_MS: ' . $durationMs,
				]),
			]);
		}
		catch (\Throwable)
		{
			// the event log must not interrupt the deletion
		}
	}

	private function resolveSiteTitle(int $siteId): string
	{
		$row = SiteTable::query()
			->setSelect(['TITLE'])
			->where('ID', $siteId)
			->setLimit(1)
			->fetch()
		;

		return trim((string)($row['TITLE'] ?? ''));
	}

	private function logFailure(int $siteId, \Throwable $throwable): void
	{
		try
		{
			$this->createLog()->write(AiSiteChatNoticeService::EVENT_SKIPPED, [
				'type' => AiSiteChatNotice::TYPE_DELETED,
				'siteId' => $siteId,
				'errorCode' => (new \ReflectionClass($throwable))->getShortName(),
			]);
		}
		catch (\Throwable)
		{
			// the deletion of the site is never interrupted by its chats
		}
	}
}
