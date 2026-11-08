<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\Models\DocumentInfo;
use Bitrix\Disk\Document\Models\DocumentInfoTable;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Closes the editing sessions the editor server never came back for.
 *
 * A browser which died, a tab which froze, a network which went away: the editor server keeps the
 * document open for whoever is still connected and sends no callback, so the key would stay in
 * "being edited" forever and the page waiting for the document would wait with it. After the threshold
 * of silence the portal ends the session itself - the key and the sessions are left alone, because the
 * editing may still come back to the same document, and the participants are told to open it again.
 */
final class InterruptedSessionService
{
	public const DATA_CHECKED = 'checked';
	public const DATA_INTERRUPTED = 'interrupted';

	/** Rows taken by one run; the rest wait for the next one. */
	private const BATCH_SIZE = 50;

	/**
	 * Seconds one run may spend on the rows it took.
	 *
	 * It is a limit on starting rows, not on the work of one: a row already started is finished. By the
	 * time the participants are told, the row carries the interrupted status and no later run will take
	 * it again, so a run which gave up halfway through a key would leave the rest of its participants
	 * with no word at all. A key with many participants can therefore overrun the budget; the rows it
	 * delays are taken by the next tick of the agent, minutes later, and they have been silent for
	 * longer than that already.
	 */
	private const RUN_BUDGET = 10;

	/**
	 * The editor server keeps a forgotten document for a day, and "open it again and the edits come
	 * back" is only true while it does. Older rows are none of the agent's business.
	 *
	 * Public because it is the border between this agent and {@see InterruptedDocumentInfoCloser}: what
	 * falls silent inside the window is ended by the agent under the key the editor server still holds,
	 * and what is older belongs to the stepper, which ends it and lets the key go.
	 */
	public const WINDOW = 86400;

	private const UNFINISHED_STATUSES = [
		DocumentInfoTable::CONTENT_STATUS_EDITING,
		DocumentInfoTable::CONTENT_STATUS_FORCE_SAVED_WITH_ERROR,
		DocumentInfoTable::CONTENT_STATUS_FORCE_SAVED,
	];

	private readonly SavingMarker $savingMarker;
	private readonly InterruptedSessionNotifier $notifier;
	private readonly DocumentChannelReporter $reporter;
	private readonly SessionReportBuilder $reportBuilder;
	private readonly LoggerInterface $logger;
	private readonly \Closure $clock;

	public function __construct(
		?SavingMarker $savingMarker = null,
		?InterruptedSessionNotifier $notifier = null,
		?DocumentChannelReporter $reporter = null,
		?SessionReportBuilder $reportBuilder = null,
		?LoggerInterface $logger = null,
		?callable $clock = null,
	)
	{
		$locator = ServiceLocator::getInstance();
		$this->savingMarker = $savingMarker ?? $locator->get(SavingMarker::class);
		$this->notifier = $notifier ?? new InterruptedSessionNotifier();
		$this->reporter = $reporter ?? new DocumentChannelReporter();
		$this->reportBuilder = $reportBuilder ?? new SessionReportBuilder();
		$this->logger = $logger ?? $locator->get(DocumentSaveService::LOGGER_SERVICE_ID);
		$this->clock = \Closure::fromCallable($clock ?? static fn(): float => microtime(true));
	}

	/**
	 * @return Result data keys DATA_CHECKED (rows taken) and DATA_INTERRUPTED (rows ended by this run).
	 */
	public function closeInterruptedSessions(): Result
	{
		$checked = 0;
		$interrupted = 0;

		if (!Configuration::isOnlyOfficeInterruptAgentEnabled())
		{
			return $this->makeResult($checked, $interrupted);
		}

		$startedAt = ($this->clock)();
		$threshold = Configuration::getOnlyOfficeInterruptThreshold();

		foreach ($this->findIdleRows($threshold) as $row)
		{
			if (($this->clock)() - $startedAt >= self::RUN_BUDGET)
			{
				break;
			}

			$checked++;
			if ($this->interrupt($row))
			{
				$interrupted++;
			}
		}

		return $this->makeResult($checked, $interrupted);
	}

	/**
	 * Keys silent for longer than the threshold which still hold participants and an active editing
	 * session. A key with no participants is left to the lazy check of the opening: without a callback
	 * the portal cannot tell whether the editor server still holds the document.
	 */
	private function findIdleRows(int $threshold): array
	{
		$now = (int)($this->clock)();

		$idleRows = DocumentInfoTable::query();

		// The session of the key is asked about by a correlated EXISTS, not by a list of keys: the
		// filter of the subquery itself (status, type, service) is covered by no index of
		// b_disk_document_session, so a list would let the optimizer materialize it - a full scan of a
		// table which grows with every document ever opened. Correlated, the subquery can only be
		// answered by a probe on IX_DISK_OODS_1 for the row at hand.
		$hasActiveEditSession = DocumentSessionTable::query()
			->setSelect(['ID'])
			->where('STATUS', DocumentSessionTable::STATUS_ACTIVE)
			->where('TYPE', DocumentSessionTable::TYPE_EDIT)
			->where(
				// A session written before the editors were told apart is an OnlyOffice one.
				Query::filter()
					->logic('or')
					->whereIn('SERVICE', [DocumentService::OnlyOffice->value, ''])
					->whereNull('SERVICE')
			)
			->whereExpr('%s = ' . $idleRows->getInitAlias() . '.EXTERNAL_HASH', ['EXTERNAL_HASH'])
		;

		// No order is asked for: any rows of the window will do, and the ones taken leave the selection
		// with the status the run writes. An order by UPDATE_TIME over three status ranges cannot come
		// from IX_DISK_DOCUMENT_INFO_STATUS_TIME, so it would turn the limit into a limit on the output
		// only and make every run read the whole of the window.
		return $idleRows
			->setSelect(['EXTERNAL_HASH', 'OBJECT_ID', 'CONTENT_STATUS', 'UPDATE_TIME'])
			->whereIn('CONTENT_STATUS', self::UNFINISHED_STATUSES)
			->where('UPDATE_TIME', '<', DateTime::createFromTimestamp($now - $threshold))
			->where('UPDATE_TIME', '>=', DateTime::createFromTimestamp($now - self::WINDOW))
			->where('USERS', '>', 0)
			->whereExists($hasActiveEditSession)
			->setLimit(self::BATCH_SIZE)
			->fetchAll()
		;
	}

	/**
	 * @return bool whether this run is the one that ended the session.
	 */
	private function interrupt(array $row): bool
	{
		$externalHash = (string)$row['EXTERNAL_HASH'];
		$previousStatus = (int)$row['CONTENT_STATUS'];
		/** @var DateTime $updateTime */
		$updateTime = $row['UPDATE_TIME'];

		// A save being written right now is silent in the same way, and interrupting it would end a
		// session which is about to succeed.
		if ($this->savingMarker->isActive($externalHash))
		{
			return false;
		}

		// The row is ended only while it looks exactly as it did in the selection: a callback which
		// arrived in between has already said more about the document than the silence does.
		if (!DocumentInfoTable::markAsInterruptedIfUnchanged($externalHash, $previousStatus, $updateTime))
		{
			return false;
		}

		$sessions = $this->findEditSessions($externalHash);
		$this->log($row, $sessions);
		$this->report($externalHash, $sessions);

		return true;
	}

	/**
	 * @param DocumentSession[] $sessions
	 */
	private function report(string $externalHash, array $sessions): void
	{
		$documentSession = $sessions[0] ?? null;
		if (!$documentSession)
		{
			return;
		}

		$report = $this->reportBuilder->buildSessionEndReport(
			$documentSession,
			DocumentInfo::loadById($externalHash),
			wasDocumentWritten: false,
			failureCategory: SaveFailureCategory::SessionInterrupted,
		);

		if ($report !== null)
		{
			$this->reporter->send($documentSession, SessionReportBuilder::COMMAND_SESSION_END, $report);
		}

		$object = $documentSession->getObject();
		if ($object)
		{
			$this->notifier->notify(
				$object,
				array_map(static fn(DocumentSession $session): int => $session->getUserId(), $sessions),
			);
		}
	}

	/**
	 * Every participant of the editing behind the key, whatever became of their own session: the one who
	 * closed the editor first waits for the document just like the one whose tab froze.
	 *
	 * @return DocumentSession[]
	 */
	private function findEditSessions(string $externalHash): array
	{
		return DocumentSession::getModelList([
			'filter' => [
				'=EXTERNAL_HASH' => $externalHash,
				'=TYPE' => DocumentSessionTable::TYPE_EDIT,
			],
		]);
	}

	/**
	 * @param DocumentSession[] $sessions
	 */
	private function log(array $row, array $sessions): void
	{
		$sessionIds = array_map(static fn(DocumentSession $session): int => (int)$session->getId(), $sessions);
		/** @var DateTime $updateTime */
		$updateTime = $row['UPDATE_TIME'];

		$this->logger->warning(
			'OnlyOffice session interrupted: object={objectId}, was={previousStatus}, idle={idleSeconds} s.',
			[
				'sessionIds' => implode(',', $sessionIds),
				'objectId' => (int)$row['OBJECT_ID'],
				'previousStatus' => (int)$row['CONTENT_STATUS'],
				'idleSeconds' => (int)($this->clock)() - $updateTime->getTimestamp(),
			],
		);
	}

	private function makeResult(int $checked, int $interrupted): Result
	{
		return (new Result())->setData([
			self::DATA_CHECKED => $checked,
			self::DATA_INTERRUPTED => $interrupted,
		]);
	}
}
