<?php

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Helper\Message\MessageInternalDateHandler;
use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\CanonicalMessageData;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MatchDecision;
use Bitrix\Mail\Internal\Service\SourceGeneration\TailMarkService;
use Bitrix\Mail\Internal\Service\SourceGeneration\UidIdentity;
use Bitrix\Mail\Internals\SourceGenerationMatchTable;
use Bitrix\Mail\MailboxDirectory;
use Bitrix\Main;
use Bitrix\Main\Text\Emoji;
use Bitrix\Mail\MailMessageTable;
use Psr\Log\LoggerInterface;

class Imap extends Mail\Helper\Mailbox
{
	const MESSAGE_PARTS_TEXT = 1;
	const MESSAGE_PARTS_ATTACHMENT = 2;
	const MESSAGE_PARTS_ALL = -1;

	private const FRESH_ARRIVAL_WINDOW = 12 * 3600;
	const MAXIMUM_SYNCHRONIZATION_LENGTHS_OF_INTERVALS = [
		100,
		50,
		25,
		12,
		6,
		3,
		1
	];
	// Optional MIME channel of the transferable identifier of a managed migrator
	const MIGRATOR_REFERENCE_HEADER = 'X-Bitrix-Mail-Migrator-Reference';
	const HISTORY_SYNC_COVERAGE_PROPERTY = 'SYNC_HISTORY_COVERAGE';
	/*
		The dir whose UIDVALIDITY changed: its rows of the previous generation are being taken over by the
		rows of the new one instead of being deleted at once. Value: reconcile_{UIDVALIDITY}.
	*/
	const GENERATION_RECONCILE_PROPERTY = 'SYNC_UIDV_RECONCILE';
	/*
		The moment the mailbox was last looked through for rows pointing at a folder the database does not
		know, as a unix timestamp. Value of the MAILBOX entity type.
	*/
	const UNKNOWN_DIRS_CHECK_PROPERTY = 'SYNC_UNKNOWN_DIRS_CHECKED_AT';
	private const UNKNOWN_DIRS_CHECK_INTERVAL = 6 * 3600;
	// 2 days: one for the RFC 3501 date truncation, one for the server-side timezone interpretation
	const HISTORY_SYNC_BOUNDARY_MARGIN = 172800;
	private const HISTORY_SYNC_LOGGER_ID = 'mail.mailbox.history_sync';
	/*
		How wide a window of a folder listing may grow, in reaches of the caller {@see listFolderUids()}.
		The letters of a folder can be anywhere among its numbers, so a window is a bet on their density:
		a wider one crosses the numbers of the deleted letters in fewer commands, a narrower one keeps
		the answer of a single command closer to the reach it was asked for.
	*/
	private const UID_WINDOW_SPAN_CAP = 8;
	/*
		How many letters the window of a lost append answer may hold before it stops being an
		answer {@see listAppendedSince()}. Only the appends of the stage itself land above the
		remembered number, and the stage appends one letter at a time, so a window wider than this
		means the assumption behind it does not hold - and a wide guess is worse than none.
	*/
	private const APPEND_WINDOW_REACH = 50;
	/*
		Client error codes that mean the link itself failed instead of the server refusing one message:
		whatever message was being handled at that moment must not be charged for them.
	*/
	private const CLIENT_TRANSPORT_ERROR_CODES = [
		Mail\Imap::ERR_CONNECT,
		Mail\Imap::ERR_COMMUNICATE,
		Mail\Imap::ERR_EMPTY_RESPONSE,
		Mail\Imap::ERR_BAD_SERVER,
	];
	/*
		What resyncDirInternal() answers with when the dir was walked, but by a list known to be
		incomplete {@see fetchMessage()}: the flags of the letters the list did hold are updated, while
		every decision that needs the whole list is left to a later run. Such a dir is not a resynced
		dir - counted as one, it lets the letters left in the moving state be declared deleted on the
		server behind a walk that never settled.
	*/
	private const RESYNC_DIR_INCOMPLETE_LIST = 'incompleteList';

	protected function getMaximumSynchronizationLengthsOfIntervals($num)
	{
		if(isset(self::MAXIMUM_SYNCHRONIZATION_LENGTHS_OF_INTERVALS[$num]))
		{
			return self::MAXIMUM_SYNCHRONIZATION_LENGTHS_OF_INTERVALS[$num];
		}
		else
		{
			return self::MAXIMUM_SYNCHRONIZATION_LENGTHS_OF_INTERVALS[count(self::MAXIMUM_SYNCHRONIZATION_LENGTHS_OF_INTERVALS)-1];
		}
	}

	protected $client;

	/** The last uid of a folder the previous syncMessages() finished with */
	protected int $lastSyncedUid = 0;
	/** @var int[] */
	private array $lastDeferredUids = [];
	/** @var int[] */
	private array $lastCompletedUids = [];
	private int $lastMessageClientErrorCount = 0;
	private ?DeliveryBoundaryService $deliveryBoundaryService = null;
	private ?DeliveryContext $deliveryContextOverride = null;

	/**
	 * The interval of uids each dir was found to hold, by the md5 of its path. Kept per object and
	 * not per process: one hit of the agent serves several mailboxes, and the dirs of one mailbox are
	 * walked in a loop - borders of one dir answer for that dir alone.
	 *
	 * @var array<string, int[]|null>
	 */
	private array $borderlineUIDsByDir = [];

	/** The last fetchMessage() dropped unusable entries, so its list may miss real letters */
	private bool $lastFetchListIncomplete = false;

	/**
	 * The same about the list of the last {@see removeExistingMessagesFromSynchronizationList()}: the
	 * ordinary synchronization fetches its letters itself, so the guard of that list is where the drop
	 * of an unusable entry becomes known on its path.
	 */
	private bool $lastSyncListIncomplete = false;

	/**
	 * What {@see resyncMessages()} reads to learn whether any list of the walk it works on may miss
	 * letters. The evidence belongs to the whole walk and not to its last portion: the rows of the
	 * excerpt are addressed by uid, and it is the server that assigns the uids of its portions.
	 *
	 * Carried in a field and not in a parameter of that method: an inheritor outside the repository may
	 * override it, and a fourth parameter would break the declaration of such an override. The careful
	 * value stands here, so a walk that never states its completeness deletes nothing by its list.
	 */
	protected bool $resyncWalkIncomplete = true;

	/**
	 * How many letters the dir of the running walk holds, as the server answered when it was opened for
	 * that walk. The completeness a mailbox without a period of its own can be shown by: every letter of
	 * the dir has a row of the version it carries now.
	 *
	 * Carried in a field for the same reason as {@see $resyncWalkIncomplete}: a parameter of
	 * finishGenerationReconcile() would break the declaration of an override outside the repository.
	 */
	protected ?int $walkedDirLetterCount = null;

	/**
	 * How many rows of a recreated folder one command brings to the numbers their letters carry now. Each
	 * of them costs a search on the mail service and a registration. Search requests are split into
	 * bounded batches so the transport limit does not become a limit of the selected set.
	 */
	protected const PLACEMENT_REBIND_SEARCH_BATCH_SIZE = 25;
	private const PLACEMENT_REBIND_SYNC_BATCH_SIZE = 10;
	private const PLACEMENT_REBIND_MESSAGE_QUERY_BATCH_SIZE = 500;

	/** The previous syncMessages() stopped because the time quota of the hit ran out */
	protected bool $stoppedOnTimeQuota = false;

	protected $linkedMessageIds = [];

	private ?HistorySyncAttemptService $historySyncAttemptService = null;

	private ?LoggerInterface $historySyncLogger = null;

	/** The folder this run has already selected for its appends */
	private ?string $appendSelectedDir = null;

	/**
	 * What that selection answered with: the epoch of the folder and the number it would give
	 * the next letter. Kept because an appended letter is recognized by the window above that
	 * number when the answer of its own append was lost.
	 */
	private ?array $appendSelectedMeta = null;

	/** The records behind the one time marks of the tail append stage */
	private ?TailMarkService $tailMarkService = null;

	protected function __construct($mailbox)
	{
		parent::__construct($mailbox);

		$this->client = new Mail\Imap(
			$mailbox['SERVER'],
			$mailbox['PORT'],
			$mailbox['USE_TLS'] == 'Y' || $mailbox['USE_TLS'] == 'S',
			$mailbox['USE_TLS'] == 'Y',
			$mailbox['LOGIN'],
			$mailbox['PASSWORD']
		);
	}

	public function getSyncStatusTotal()
	{
		$currentDir = null;

		if (!empty($this->syncParams['currentDir']))
		{
			$currentDir = $this->syncParams['currentDir'];
		}

		$totalSyncDirs = count($this->getDirsHelper()->getSyncDirs());
		$currentSyncDirPath = MailboxDirectoryHelper::getCurrentSyncDir();
		$currentSyncDir = $this->getDirsHelper()->getDirByPath($currentSyncDirPath);

		if ($totalSyncDirs > 0 && $currentSyncDir != null)
		{
			$currentSyncDirMessages = Mail\MailMessageUidTable::getList([
				'select' => [
					new Main\Entity\ExpressionField('TOTAL', 'COUNT(1)'),
				],
				'filter' => $this->peekGenerationScope()->apply([
					'=MAILBOX_ID'  => $this->mailbox['ID'],
					'=DIR_MD5'     => $currentSyncDir->getDirMd5(),
					'==DELETE_TIME' => 0,
				]),
			])->fetch();

			$currentSyncDirMessagesCount = (int)$currentSyncDirMessages['TOTAL'];
			$currentSyncDirMessagesAll = (int)$currentSyncDir->getMessageCount();
			$currentSyncDirPosition = $this->getDirsHelper()->getCurrentSyncDirPositionOrdered(
				$currentSyncDir->getPath(),
				$currentDir,
			);

			if ($currentDir != null) {
				$totalSyncDirs--;
			}

			if ($currentSyncDirMessagesAll <= 0)
			{
				$progress = ($currentSyncDirPosition + 1) / $totalSyncDirs;
			}
			else
			{
				$progress = ($currentSyncDirMessagesCount / $currentSyncDirMessagesAll + $currentSyncDirPosition) / $totalSyncDirs;
			}

			return $progress;
		}
		else
		{
			return parent::getSyncStatus();
		}
	}

	public function getSyncStatus()
	{
		if (!empty($this->syncParams['currentDir']))
		{
			$currentSyncDir = $this->getDirsHelper()->getDirByPath($this->syncParams['currentDir']);
		}

		if (!empty($currentSyncDir))
		{
			$currentSyncDirMessages = Mail\MailMessageUidTable::getList([
				'select' => [
					new Main\Entity\ExpressionField('TOTAL', 'COUNT(1)'),
				],
				'filter' => $this->peekGenerationScope()->apply([
					'=MAILBOX_ID'  => $this->mailbox['ID'],
					'=DIR_MD5'     => $currentSyncDir->getDirMd5(),
					'==DELETE_TIME' => 0,
				]),
			])->fetch();

			$currentSyncDirMessagesCount = (int) $currentSyncDirMessages['TOTAL'];
			$currentSyncDirMessagesAll = (int) $currentSyncDir->getMessageCount();

			if ($currentSyncDirMessagesAll > 0)
			{
				return ($currentSyncDirMessagesCount / $currentSyncDirMessagesAll);
			}
		}

		return 1;
	}

	public function checkMessagesForExistence($dirPath ='INBOX',$UIDs = [])
	{
		$existingUIDs = [];
		$this->unansweredUidsOfLastCheck = [];

		if(!empty($UIDs))
		{
			/*
				If a non-existing id gets among the existing ones,
				some mailers may issue an error (instead of issuing existing messages),
				then we will think that the letters disappeared on the mail service,
				although in fact there were existing messages among them.
				But the messages can be deleted legally,
				it's just that the mail has not been resynchronized for a long time.
				In this case, small samples are needed in order to catch existing messages in any of them.
			*/
			$chunks = array_chunk($UIDs, 5);

			foreach ($chunks as $chunk)
			{
				$messages = $this->client->fetch(
					true,
					$dirPath,
					join(',', $chunk),
					'(UID FLAGS)',
					$error,
					'list'
				);

				if ($messages === false)
				{
					/*
						The request failed, so the mail server said nothing about these uids at all. An empty
						answer is a different matter: there the server did answer, and the answer is that it
						holds none of them.
					*/
					$this->unansweredUidsOfLastCheck = array_merge($this->unansweredUidsOfLastCheck, $chunk);

					continue;
				}

				foreach ((array)$messages as $item)
				{
					if (!isset($item['UID']))
					{
						continue;
					}

					/*
						The server answered about the letter, so it holds it. An entry whose attribute parse
						broke off before the flags tells nothing about the deletion flag, and an unknown flag
						must not turn into the answer "the letter is gone": that answer is what the rows of
						the mailbox are deleted by.
					*/
					$messageDeleted = isset($item['FLAGS']) && preg_grep('/^ \x5c Deleted $/ix', $item['FLAGS']);

					if(!$messageDeleted)
					{
						$existingUIDs[] = $item['UID'];
					}
				}
			}
		}

		return $existingUIDs;
	}

	public function resyncIsOldStatus()
	{
		if (!$this->checkSyncGenerationContext())
		{
			return false;
		}

		$mailboxID = $this->mailbox['ID'];
		$directoryHelper = $this->getDirsHelper();
		$syncDirs = $directoryHelper->getSyncDirs();

		$numberOfUnSynchronizedDirs = count($syncDirs);

		foreach ($syncDirs as $dir)
		{
			$dirPath = $dir->getPath();
			$dirId = $dir->getId();

			$internalDate = \Bitrix\Mail\Helper::getLastDeletedOldMessageInternaldate(
				$mailboxID,
				$dirPath,
				generationScope: $this->getGenerationScope(),
			);

			$keyRow = [
				'MAILBOX_ID' => $mailboxID,
				'ENTITY_TYPE' => 'DIR',
				'ENTITY_ID' => $dirId,
				'PROPERTY_NAME' => 'SYNC_IS_OLD_STATUS',
			];

			$filter = [
				'=MAILBOX_ID' => $keyRow['MAILBOX_ID'],
				'=ENTITY_TYPE' => $keyRow['ENTITY_TYPE'],
				'=ENTITY_ID' => $keyRow['ENTITY_ID'],
				'=PROPERTY_NAME' => $keyRow['PROPERTY_NAME'],
			];

			$startValue = 'started_for_date_'.$internalDate;

			if(Mail\Internals\MailEntityOptionsTable::getCount($filter))
			{
				if(Mail\Internals\MailEntityOptionsTable::getList([
						'select' => [
							'VALUE',
						],
						'filter' => $filter,
					])->fetchAll()[0]['VALUE'] !== 'completed')
				{
					Mail\Internals\MailEntityOptionsTable::update(
						$keyRow,
						['VALUE' => $startValue]
					);

					$synchronizationSuccess = $this->setIsOldStatusesLowerThan($internalDate,$dirPath,$mailboxID);

					if($synchronizationSuccess)
					{
						Mail\Internals\MailEntityOptionsTable::update(
							$keyRow,
							['VALUE' => 'completed']
						);
						$numberOfUnSynchronizedDirs--;
					}
				}
				else
				{
					$numberOfUnSynchronizedDirs--;
				}
			}
			else
			{
				$fields = $keyRow;
				$fields['VALUE'] = $startValue;
				Mail\Internals\MailEntityOptionsTable::add(
					$fields
				);

				$synchronizationSuccess = $this->setIsOldStatusesLowerThan($internalDate,$dirPath,$mailboxID);

				if($synchronizationSuccess)
				{
					\Bitrix\Mail\Internals\MailEntityOptionsTable::update(
						$keyRow,
						['VALUE' => 'completed']
					);
					$numberOfUnSynchronizedDirs--;
				}
			}
		}

		if($numberOfUnSynchronizedDirs === 0)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	public function syncFirstDay()
	{
		if (!$this->checkSyncGenerationContext())
		{
			return false;
		}

		$mailboxID = $this->mailbox['ID'];
		$directoryHelper = $this->getDirsHelper();
		$syncDirs = $directoryHelper->getSyncDirs();

		$numberOfUnSynchronizedDirs = count($syncDirs);

		foreach ($syncDirs as $dir)
		{
			$dirPath = $dir->getPath();
			$dirId = $dir->getId();

			$internalDate = \Bitrix\Mail\Helper::getStartInternalDateForDir($mailboxID,$dirPath);

			$keyRow = [
				'MAILBOX_ID' => $mailboxID,
				'ENTITY_TYPE' => 'DIR',
				'ENTITY_ID' => $dirId,
				'PROPERTY_NAME' => 'SYNC_FIRST_DAY',
			];

			$filter = [
				'=MAILBOX_ID' => $keyRow['MAILBOX_ID'],
				'=ENTITY_TYPE' => $keyRow['ENTITY_TYPE'],
				'=ENTITY_ID' => $keyRow['ENTITY_ID'],
				'=PROPERTY_NAME' => $keyRow['PROPERTY_NAME'],
			];

			$startValue = 'started_for_date_'.$internalDate;

			if(Mail\Internals\MailEntityOptionsTable::getCount($filter))
			{
				if(Mail\Internals\MailEntityOptionsTable::getList([
						'select' => [
							'VALUE',
						],
						'filter' => $filter,
					])->fetchAll()[0]['VALUE'] !== 'completed')
				{
					Mail\Internals\MailEntityOptionsTable::update(
						$keyRow,
						['VALUE' => $startValue]
					);

					\CTimeZone::Disable();
					$synchronizationSuccess = $this->syncDirForSpecificDay($dirPath,$internalDate);
					\CTimeZone::Enable();

					if($synchronizationSuccess)
					{
						Mail\Internals\MailEntityOptionsTable::update(
							$keyRow,
							['VALUE' => 'completed']
						);
						$numberOfUnSynchronizedDirs--;
					}
				}
				else
				{
					$numberOfUnSynchronizedDirs--;
				}
			}
			else
			{
				$fields = $keyRow;
				$fields['VALUE'] = $startValue;
				Mail\Internals\MailEntityOptionsTable::add(
					$fields
				);

				\CTimeZone::Disable();
				$synchronizationSuccess = $this->syncDirForSpecificDay($dirPath,$internalDate);
				\CTimeZone::Enable();

				if($synchronizationSuccess)
				{
					\Bitrix\Mail\Internals\MailEntityOptionsTable::update(
						$keyRow,
						['VALUE' => 'completed']
					);
					$numberOfUnSynchronizedDirs--;
				}
			}
		}

		if($numberOfUnSynchronizedDirs === 0)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	protected function syncInternal()
	{
		$syncReport = $this->syncMailbox();
		if (false === $syncReport['syncCount'])
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());
		}

		return $syncReport;
	}

	protected function createMessage(Main\Mail\Mail $message, array $fields = array(), int $authorId = 0)
	{
		$dirPath = $this->getDirsHelper()->getOutcomePath() ?: 'INBOX';

		$fields = array_merge(
			$fields,
			array(
				'DIR_MD5'  => md5($dirPath),
				'DIR_UIDV' => 0,
				'MSG_UID'  => 0,
			)
		);

		return parent::createMessage($message, $fields, $authorId);
	}

	public function syncOutgoing()
	{
		$this->cacheDirs();

		parent::syncOutgoing();
	}

	public function uploadMessage(Main\Mail\Mail $message, array &$excerpt = null)
	{
		$dirPath = $this->getDirsHelper()->getOutcomePath() ?: 'INBOX';

		$data = $this->client->select($dirPath, $error);

		if (false === $data)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		if (!empty($excerpt['__unique_headers']))
		{
			if ($this->client->searchByHeader(false, $dirPath, $excerpt['__unique_headers'], $error))
			{
				return false;
			}
		}

		if (!empty($excerpt['ID']))
		{
			class_exists('Bitrix\Mail\Helper');

			Mail\DummyMail::overwriteMessageHeaders(
				$message,
				array(
					'X-Bitrix-Mail-Message-UID' => $excerpt['ID'],
				)
			);
		}

		$result = $this->client->append(
			$dirPath,
			array('\Seen'),
			new \DateTime,
			sprintf(
				'%1$s%3$s%3$s%2$s',
				$message->getHeaders(),
				$message->getBody(),
				$message->getMailEol()
			),
			$error
		);

		if (false === $result)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		$this->syncDir($dirPath);

		return $result;
	}

	/**
	 * Puts one assembled message into a folder of the prepared generation and reports the
	 * coordinates the server gave it.
	 *
	 * Not a second uploadMessage(): that one sends a letter the user has just written, and
	 * four of the things it does would damage the history here. It stamps the outgoing
	 * header X-Bitrix-Mail-Message-UID, which a later read of the letter answers by
	 * rewriting the named placement row in place - and the row it names belongs to the
	 * archived generation, whose coordinates would be lost. It stamps the server mark with
	 * "now" instead of the mark the letter really arrived under, it defaults to the Sent
	 * folder, and it walks the whole folder after every single letter.
	 *
	 * The folder is selected once per run instead of once per letter, and nothing is read
	 * back afterwards: what the letter got is already in the answer of the command.
	 *
	 * @param string $mime The message as it travels, headers and body.
	 * @param \DateTime $internalDate The mark of the server the letter arrived on, which is
	 *        what APPEND asks for as a parameter of its own.
	 * @param string[] $flags IMAP flags of the letter, e.g. ['\Seen'].
	 * @param bool|null $answered Receives whether the source answered this command at all.
	 *        False is the one case a caller must not repeat the letter in: the command went
	 *        out and the link failed under it, so the server may well have carried it out and
	 *        a second copy of a letter cannot be taken back. A refusal the server named, and a
	 *        letter that never left the boundary below, are answers - those may be repeated.
	 * @return array{uidValidity: int, uid: int}|null|false The coordinates the server
	 *         assigned; null when the letter was appended and the server named no
	 *         coordinates; false when it was not appended at all - refused by the boundary
	 *         below, which leaves no error, or by the server, which leaves one.
	 */
	public function appendMessage(
		string $dirPath,
		string $mime,
		\DateTime $internalDate,
		array $flags = [],
		?bool &$answered = null,
	): array|null|false
	{
		$answered = true;

		if (!$this->acceptsAppendInto($dirPath) || !$this->selectForAppend($dirPath))
		{
			// Nothing has been sent: the command is refused here, before the letter is on the wire
			return false;
		}

		$errorsBefore = count($this->client->getErrors());
		$appended = $this->client->append($dirPath, $flags, $internalDate, $mime, $error);

		if ($appended === false)
		{
			$answered = !self::hasTransportError($this->errorsRaisedSince($errorsBefore));
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		$coordinates = $this->assignedCoordinates($appended);

		if ($coordinates !== null && $this->appendSelectedDir === $dirPath)
		{
			/*
				The number of the next letter moves on with every letter taken, and keeping it here
				costs nothing: the window a resumed pass looks a letter up in stays the one letter
				wide it should be, instead of widening with every append of the pass.
			*/
			$this->appendSelectedMeta['uidnext'] = max(
				(int)($this->appendSelectedMeta['uidnext'] ?? 0),
				$coordinates['uid'] + 1,
			);
		}

		return $coordinates;
	}

	/**
	 * Whether the folder already holds a letter carrying those header fields.
	 *
	 * The insurance of a resumed tail append: a pass that broke off between the append of a
	 * letter and the verdict about it left the letter on the server, and appending it again
	 * would leave the mailbox with two copies. The command answers with the NUMBER of the
	 * letters that match and not with their numbers, which is why the answer is a yes or a
	 * no and nothing more - the coordinates of the letter cannot be recovered this way.
	 *
	 * Bounded by the same folders as the append itself: this is a question about a folder of
	 * a generation being prepared, and the folder of the same path on the source serving the
	 * user right now is nobody's business here.
	 *
	 * @param array<string, string> $header Field name => the value it has to carry.
	 * @return bool|null Null when the folder could not be asked at all.
	 */
	public function holdsMessageWithHeader(string $dirPath, array $header): ?bool
	{
		if ($header === [] || !$this->acceptsAppendInto($dirPath))
		{
			return null;
		}

		$found = $this->client->searchByHeader(true, $dirPath, $header, $error);

		if ($found === false)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return null;
		}

		return is_array($found) ? $found !== [] : (int)$found > 0;
	}

	/**
	 * Whether this run may append a letter into that folder.
	 *
	 * Both conditions are needed, and the folder alone would not do: the folders of the
	 * prepared generation and of the active one usually answer to the same path, so a
	 * folder found by path in an ordinary run is the folder the user is served from right
	 * now - and a letter put there is a letter nobody asked us to send.
	 */
	private function acceptsAppendInto(string $dirPath): bool
	{
		try
		{
			$accessLevel = $this->getGenerationContext()->getAccessLevel();
		}
		catch (Main\SystemException)
		{
			return false;
		}

		if ($accessLevel !== Context::ACCESS_APPEND_ONLY)
		{
			return false;
		}

		foreach ($this->getDirsHelper()->getSyncDirs() as $dir)
		{
			if ($dir->getPath() === $dirPath)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * APPEND names the folder itself and needs none selected, so this is a probe: the
	 * database may know a folder the server has lost, and one refusal for the whole folder
	 * beats one per letter. Once per run is therefore enough, and a folder another command
	 * of the same run selected afterwards changes nothing about that.
	 */
	private function selectForAppend(string $dirPath): bool
	{
		return $this->selectedForAppend($dirPath) !== null;
	}

	/**
	 * The same one selection of the run, with the answer it came back with.
	 *
	 * @return array|null The meta of the folder - its epoch as uidvalidity and the number of the
	 *         next letter as uidnext; null when the folder cannot be selected at all.
	 */
	private function selectedForAppend(string $dirPath): ?array
	{
		if ($this->appendSelectedDir === $dirPath && $this->appendSelectedMeta !== null)
		{
			return $this->appendSelectedMeta;
		}

		$meta = $this->client->select($dirPath, $error);

		if ($meta === false)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return null;
		}

		$this->appendSelectedDir = $dirPath;
		$this->appendSelectedMeta = is_array($meta) ? $meta : [];

		return $this->appendSelectedMeta;
	}

	/**
	 * The epoch of the folder and the number it would give the next letter appended into it.
	 *
	 * What it is for: the answer of an APPEND can be lost - the process dies with it - and then
	 * nothing addresses the letter that may or may not be up there. Written down BEFORE the
	 * append, this pair turns into a window a resumed pass can look the letter up in
	 * {@see listAppendedSince()}. It costs no round trip of its own: the number comes back with
	 * the one selection the run makes anyway.
	 *
	 * The number is a lower bound and not a promise - a server may give the letter a higher one -
	 * and a bound is all the window needs.
	 *
	 * @return array{uidValidity: int, uid: int}|null Null when the folder cannot be asked, or
	 *         when the server names no such number: there is no window then, and what an append
	 *         nobody can answer about counts as is decided elsewhere.
	 */
	public function nextAppendPosition(string $dirPath): ?array
	{
		if (!$this->acceptsAppendInto($dirPath))
		{
			return null;
		}

		$meta = $this->selectedForAppend($dirPath);

		if ($meta === null)
		{
			return null;
		}

		$uidValidity = (int)($meta['uidvalidity'] ?? 0);
		$uid = (int)($meta['uidnext'] ?? 0);

		return $uidValidity > 0 && $uid > 0 ? ['uidValidity' => $uidValidity, 'uid' => $uid] : null;
	}

	/**
	 * The letters of the folder numbered at or above that one, each with what an append of ours
	 * is recognized by: its number, its header block and the mark the server received it under.
	 *
	 * The window is ours by construction. Nobody else writes into a folder of a generation being
	 * prepared: the transfer service has finished before the stage starts, and the delivery of
	 * the mailbox goes to the source it is still served from until the switch. So a letter of
	 * ours is either in this window - the append happened - or the window is empty and it never
	 * did.
	 *
	 * @param int $uidValidity The epoch the number was remembered under. A folder that changed
	 *        it renumbered its letters, and the remembered number means nothing anymore.
	 * @return array<int, array{uid: int, header: string, internalDate: int}>|null An empty array
	 *         is the answer "the folder holds nothing above that number"; null is no answer at
	 *         all - the epoch changed, the source refused, or the window is too wide to be the
	 *         one letter it should be.
	 */
	public function listAppendedSince(string $dirPath, int $uidValidity, int $fromUid): ?array
	{
		if ($fromUid <= 0 || $uidValidity <= 0 || !$this->acceptsAppendInto($dirPath))
		{
			return null;
		}

		$meta = $this->selectedForAppend($dirPath);

		if ($meta === null || (int)($meta['uidvalidity'] ?? 0) !== $uidValidity)
		{
			return null;
		}

		$uids = $this->client->getUidsSince($dirPath, 0, maximumUid: null, minimumUid: $fromUid);

		if ($uids === false)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return null;
		}

		$uids = array_values(array_filter(array_map('intval', (array)$uids), static fn (int $uid): bool => $uid > 0));

		if (count($uids) > self::APPEND_WINDOW_REACH)
		{
			// Not the window of one append anymore: whatever this folder holds, it is not answerable
			return null;
		}

		return $this->fetchAppendWindow($dirPath, $uids);
	}

	/**
	 * @param int[] $uids
	 * @return array<int, array{uid: int, header: string, internalDate: int}>|null
	 */
	private function fetchAppendWindow(string $dirPath, array $uids): ?array
	{
		$letters = [];

		foreach (array_chunk($uids, 10) as $chunk)
		{
			$messages = $this->client->fetch(
				true,
				$dirPath,
				join(',', $chunk),
				'(UID INTERNALDATE BODY.PEEK[HEADER])',
				$error,
				'list',
			);

			if ($messages === false)
			{
				$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

				return null;
			}

			foreach ((array)$messages as $message)
			{
				$stamp = strtotime((string)($message['INTERNALDATE'] ?? ''));

				$letters[] = [
					'uid' => (int)($message['UID'] ?? 0),
					'header' => (string)($message['BODY[HEADER]'] ?? ''),
					'internalDate' => $stamp === false ? 0 : $stamp,
				];
			}
		}

		return $letters;
	}

	/**
	 * @param string|true $appended What the APPEND command came back with: the pair of
	 *        APPENDUID, or true from a server that reports no such pair.
	 * @return array{uidValidity: int, uid: int}|null
	 */
	private function assignedCoordinates($appended): ?array
	{
		if (!is_string($appended) || !preg_match('/^(\d+):(\d+)$/', $appended, $matches))
		{
			return null;
		}

		$uidValidity = (int)$matches[1];
		$uid = (int)$matches[2];

		// Both numbers of APPENDUID start at one: a zero is a coordinate nothing can be built from
		if ($uidValidity <= 0 || $uid <= 0)
		{
			return null;
		}

		return ['uidValidity' => $uidValidity, 'uid' => $uid];
	}

	public function downloadMessage(array &$excerpt)
	{
		if (empty($excerpt['MSG_UID']) || empty($excerpt['DIR_MD5']) || !$this->servesPlacement($excerpt))
		{
			return false;
		}

		$dirPath = $this->getDirsHelper()->getDirPathByHash($excerpt['DIR_MD5']);
		if (empty($dirPath))
		{
			return false;
		}

		if (!$this->servesFolderVersion($excerpt, $dirPath))
		{
			return false;
		}

		$body = $this->client->fetch(true, $dirPath, $excerpt['MSG_UID'], '(BODY.PEEK[])', $error);

		if (false === $body)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		return empty($body['BODY[]']) ? null : $body['BODY[]'];
	}

	/**
	 * Whether the physical coordinates of that placement address the source this engine
	 * talks to.
	 *
	 * A placement of a generation the mailbox has left names the folder and the uid of a
	 * source it no longer talks to, while the folders of two generations usually answer to
	 * the same path - so the same uid on the current source is another letter of the
	 * mailbox, and a download through such a placement would fill the message with the body
	 * and the attachments of that letter. A placement that names no generation is written
	 * by generation unaware code and always belongs to the source in use.
	 */
	private function servesPlacement(array $excerpt): bool
	{
		$generationId = (int)($excerpt['GENERATION_ID'] ?? 0);

		return $generationId === 0 || $this->peekGenerationScope()->includes($generationId);
	}

	/**
	 * Whether the uid of that placement is a uid of the folder as the source keeps it now.
	 *
	 * A folder the server has renumbered answers with a UIDVALIDITY of its own, and every uid
	 * written under the previous one is void: the same number in the new numbering names another
	 * letter of the same folder, so a download through such a placement would fill the message
	 * with the body and the attachments of that letter and store them. The generation boundary
	 * {@see servesPlacement()} says nothing of this - it answers for the hand-over of the whole
	 * mailbox between sources, while a renumbering happens to a folder of the one source in use.
	 *
	 * A placement carrying no version is left to the ordinary path: it is written by version
	 * unaware code and by the outgoing route, which awaits its uid from the mail service. A folder
	 * with a known version is refused when the folder cannot report its current version: without
	 * that proof, the following fetch may address another letter after a recreation.
	 */
	private function servesFolderVersion(array $excerpt, string $dirPath): bool
	{
		$storedVersion = (int)($excerpt['DIR_UIDV'] ?? 0);

		if ($storedVersion <= 0)
		{
			return true;
		}

		$error = null;
		$meta = $this->client->select($dirPath, $error);

		if (empty($meta['uidvalidity']))
		{
			return false;
		}

		return (int)$meta['uidvalidity'] === $storedVersion;
	}

	/**
	 * A number written under a version the folder has left is no question to ask the server: the folder
	 * numbers its letters anew, so the answer would be about another letter of it, and a letter answered
	 * for by mistake stays in the portal for good - that is what keeps the cards of letters the server no
	 * longer holds in the list of the user. Such a row is left out of the question, and the deletion
	 * decides about it on what it really knows: the folder does not answer for that number any more.
	 *
	 * A folder that cannot be opened is not judged at all: the version it carries is unknown, and the
	 * request that follows fails the way it always did.
	 */
	protected function placementsTheSourceCanBeAskedAbout(array $messagesForRemove, string $dirPath): array
	{
		$error = null;
		$meta = $this->client->select($dirPath, $error);
		$currentVersion = (int)($meta['uidvalidity'] ?? 0);

		if ($currentVersion <= 0)
		{
			return $messagesForRemove;
		}

		return array_values(array_filter(
			$messagesForRemove,
			static function ($row) use ($currentVersion)
			{
				$version = (int)($row['DIR_UIDV'] ?? 0);

				// a row awaiting its number from the mail service belongs to no version of the folder
				return $version === 0 || $version === $currentVersion;
			}
		));
	}

	/**
	 * The rows of a folder the server has recreated, brought to the numbers that folder keeps their
	 * letters under now.
	 *
	 * A row written under a version the folder has left addresses nothing: the same number names another
	 * letter of the folder, so a command carried to it would be carried out on that letter. Refusing every
	 * such row would refuse the letters themselves - a recreated folder stands like that until the
	 * synchronization has walked it whole, and a letter outside the period of the mailbox stands like that
	 * for good - so the letter is looked for instead, by the identifier its own header carries. The row
	 * that addresses it is then registered the ordinary way, which takes the void row over with the letter
	 * it holds, its links and the date it arrived; the command goes to the row that came out of it.
	 *
	 * A letter the folder no longer holds is answered for as well: its row is named with nothing, and the
	 * caller carries no command to a number that belongs to another letter now.
	 *
	 * Searches are grouped by folder and split into bounded batches. The bound limits one IMAP command,
	 * not the selected set: every unambiguous match is registered before the command is carried out.
	 *
	 * @param iterable $rows Rows of b_mail_message_uid with ID, MESSAGE_ID, DIR_MD5 and DIR_UIDV.
	 * @return Main\Result Success data contains `placements`: old row id => current row id or null.
	 */
	public function rebindPlacementsOfPreviousFolderVersion(iterable $rows): Main\Result
	{
		$rebound = [];
		$currentVersions = [];
		$candidatesByDir = [];

		foreach ($rows as $row)
		{
			$messageId = (int)($row['MESSAGE_ID'] ?? 0);
			$storedVersion = (int)($row['DIR_UIDV'] ?? 0);
			$dirMd5 = (string)($row['DIR_MD5'] ?? '');

			// A row awaiting its number from the mail service belongs to no version of the folder
			if ($messageId <= 0 || $storedVersion <= 0 || $dirMd5 === '')
			{
				continue;
			}

			if (!array_key_exists($dirMd5, $currentVersions))
			{
				$dirPath = $this->getDirsHelper()->getDirPathByHash($dirMd5);

				if (empty($dirPath))
				{
					return $this->failedPlacementRebind();
				}

				$error = null;
				$meta = $this->client->select($dirPath, $error);
				$currentVersion = (int)($meta['uidvalidity'] ?? 0);
				if ($meta === false || $currentVersion <= 0)
				{
					return $this->failedPlacementRebind();
				}

				$currentVersions[$dirMd5] = [
					'path' => $dirPath,
					'version' => $currentVersion,
				];
			}

			$dirPath = $currentVersions[$dirMd5]['path'];
			$currentVersion = $currentVersions[$dirMd5]['version'];

			if ($currentVersion === $storedVersion)
			{
				continue;
			}

			$rebound[(string)$row['ID']] = null;
			$candidatesByDir[$dirMd5]['path'] = $dirPath;
			$candidatesByDir[$dirMd5]['version'] = $currentVersion;
			$candidatesByDir[$dirMd5]['rows'][] = [
				'id' => (string)$row['ID'],
				'messageId' => $messageId,
				'pair' => $row,
			];
		}

		if ($candidatesByDir === [])
		{
			return $this->successfulPlacementRebind($rebound);
		}

		$messageIds = [];
		foreach ($candidatesByDir as $dir)
		{
			$messageIds = array_merge($messageIds, array_column($dir['rows'], 'messageId'));
		}
		$headerMessageIds = $this->loadHeaderMessageIds($messageIds);
		$uidsByDirAndMessageId = [];
		$localMessageIdsByDirAndHeader = [];

		foreach ($candidatesByDir as $dirMd5 => $dir)
		{
			$headers = [];
			foreach ($dir['rows'] as $row)
			{
				$headerMessageId = $headerMessageIds[$row['messageId']] ?? '';
				if ($headerMessageId !== '')
				{
					$headers[$headerMessageId] = $headerMessageId;
				}
			}
			$localMessageIdsByDirAndHeader[$dirMd5] = $this->loadLocalMessageIdsByHeader(
				$dirMd5,
				array_values($headers),
			);

			$requestedMessageIds = [];
			foreach ($localMessageIdsByDirAndHeader[$dirMd5] as $headerMessageId => $localMessageIds)
			{
				if (count($localMessageIds) === 1)
				{
					$requestedMessageIds[] = $headerMessageId;
				}
			}

			foreach (array_chunk($requestedMessageIds, self::PLACEMENT_REBIND_SEARCH_BATCH_SIZE) as $batch)
			{
				$matched = $this->client->getUidsByMessageIds($dir['path'], $batch);
				if ($matched === false)
				{
					$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

					return $this->failedPlacementRebind();
				}
				if ($this->isTimeQuotaExceeded())
				{
					$this->stoppedOnTimeQuota = true;

					return $this->failedPlacementRebind();
				}

				$uidsByDirAndMessageId[$dirMd5] = array_replace(
					$uidsByDirAndMessageId[$dirMd5] ?? [],
					$matched,
				);
			}
		}

		foreach ($candidatesByDir as $dirMd5 => $dir)
		{
			$uids = [];
			$generationPairsByUid = [];
			foreach ($dir['rows'] as $row)
			{
				$headerMessageId = $headerMessageIds[$row['messageId']] ?? '';
				$matchedUids = $uidsByDirAndMessageId[$dirMd5][$headerMessageId] ?? [];
				if (
					count($localMessageIdsByDirAndHeader[$dirMd5][$headerMessageId] ?? []) === 1
					&& count($matchedUids) === 1
				)
				{
					$uid = (int)reset($matchedUids);
					$uids[$uid] = $uid;
					$generationPairsByUid[$uid] = $row['pair'];
				}
			}

			foreach (array_chunk(array_values($uids), self::PLACEMENT_REBIND_SYNC_BATCH_SIZE) as $uidBatch)
			{
				/*
					The period of the mailbox is obeyed as everywhere else: a letter outside it gets its row
					and gives it the letter of the void one - that is the whole repair - while its body is
					left undownloaded, exactly as the ordinary walk would leave it.
				*/
				$batchPairs = array_intersect_key($generationPairsByUid, array_fill_keys($uidBatch, true));
				$synced = $this->syncMessages(
					$this->mailbox['ID'],
					$dir['path'],
					$uidBatch,
					ignoreSyncFrom: false,
					failOnLocalError: true,
					generationPairsByUid: $batchPairs,
				);

				$completedUids = array_fill_keys($this->getLastCompletedUids(), true);
				$unfinishedUids = array_filter(
					$uidBatch,
					function (int $uid) use ($completedUids, $batchPairs, $dirMd5, $dir): bool {
						if (isset($completedUids[$uid]))
						{
							return false;
						}

						$pair = $batchPairs[$uid] ?? [];

						return $this->readPlacementOfFolderVersion(
							(int)($pair['MESSAGE_ID'] ?? 0),
							$dirMd5,
							$dir['version'],
						) === null;
					},
				);

				if (
					!$synced
					|| $this->hasStoppedOnTimeQuota()
					|| $this->getLastDeferredUids() !== []
					|| $unfinishedUids !== []
				)
				{
					return $this->failedPlacementRebind();
				}
			}

			foreach ($dir['rows'] as $row)
			{
				$rebound[$row['id']] = $this->readPlacementOfFolderVersion(
					$row['messageId'],
					$dirMd5,
					$dir['version'],
				);
			}
		}

		return $this->successfulPlacementRebind($rebound);
	}

	/** @param array<string, string|null> $placements */
	private function successfulPlacementRebind(array $placements): Main\Result
	{
		$result = new Main\Result();
		$result->setData(['placements' => $placements]);

		return $result;
	}

	/**
	 * @param string[] $headerMessageIds
	 * @return array<string, array<int, true>> Header Message-ID => logical message ids in this dir.
	 */
	private function loadLocalMessageIdsByHeader(string $dirMd5, array $headerMessageIds): array
	{
		$result = [];
		foreach (array_chunk(array_values(array_unique($headerMessageIds)), self::PLACEMENT_REBIND_MESSAGE_QUERY_BATCH_SIZE) as $batch)
		{
			$rows = $this->listMessages([
				'select' => [
					'MESSAGE_ID',
					'HEADER_MESSAGE_ID' => 'MESSAGE_TABLE.MSG_ID',
				],
				'filter' => [
					'=DIR_MD5' => $dirMd5,
					'>DIR_UIDV' => 0,
					'==DELETE_TIME' => 0,
					'@MESSAGE_TABLE.MSG_ID' => $batch,
				],
			], false);

			while ($row = $rows->fetch())
			{
				$headerMessageId = trim((string)($row['HEADER_MESSAGE_ID'] ?? ''));
				if ($headerMessageId !== '')
				{
					$result[$headerMessageId][(int)$row['MESSAGE_ID']] = true;
				}
			}
		}

		return $result;
	}

	private function failedPlacementRebind(): Main\Result
	{
		$result = new Main\Result();
		$errors = $this->client->getErrors()->toArray();

		if ($errors === [])
		{
			$errors[] = new Main\Error('', 'MAIL_CLIENT_SYNC_ERROR');
		}

		$result->addErrors($errors);

		return $result;
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, string> Message table id => Message-ID without surrounding whitespace.
	 */
	private function loadHeaderMessageIds(array $messageIds): array
	{
		$result = [];
		foreach (array_chunk(array_values(array_unique($messageIds)), self::PLACEMENT_REBIND_MESSAGE_QUERY_BATCH_SIZE) as $batch)
		{
			$rows = Mail\MailMessageTable::getList([
				'select' => ['ID', 'MSG_ID'],
				'filter' => ['@ID' => $batch],
			]);

			while ($row = $rows->fetch())
			{
				$result[(int)$row['ID']] = trim((string)($row['MSG_ID'] ?? ''));
			}
		}

		return $result;
	}

	/** The row of that message in that folder under the version the folder carries now, if there is one. */
	private function readPlacementOfFolderVersion(int $messageId, string $dirMd5, int $version): ?string
	{
		$row = Mail\MailMessageUidTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailbox['ID'],
				'=MESSAGE_ID' => $messageId,
				'=DIR_MD5' => $dirMd5,
				'=DIR_UIDV' => $version,
				'==DELETE_TIME' => 0,
			],
		]);

		return isset($row['ID']) ? (string)$row['ID'] : null;
	}

	public function downloadMessageParts(array &$excerpt, Mail\Imap\BodyStructure $bodystructure, $flags = Imap::MESSAGE_PARTS_ALL)
	{
		if (empty($excerpt['MSG_UID']) || empty($excerpt['DIR_MD5']) || !$this->servesPlacement($excerpt))
		{
			return false;
		}

		$dirPath = $this->getDirsHelper()->getDirPathByHash($excerpt['DIR_MD5']);
		if (empty($dirPath))
		{
			return false;
		}

		if (!$this->servesFolderVersion($excerpt, $dirPath))
		{
			return false;
		}

		$rfc822Parts = array();

		$select = array_filter(
			$bodystructure->traverse(
				function (Mail\Imap\BodyStructure $item) use ($flags, &$rfc822Parts)
				{
					if ($item->isMultipart())
					{
						return;
					}

					$isTextItem = $item->isBodyText();
					if ($flags & ($isTextItem ? Imap::MESSAGE_PARTS_TEXT : Imap::MESSAGE_PARTS_ATTACHMENT))
					{
						// due to yandex bug
						if ('message' === $item->getType() && 'rfc822' === $item->getSubtype())
						{
							$rfc822Parts[] = $item;

							return sprintf('BODY.PEEK[%1$s.HEADER] BODY.PEEK[%1$s.TEXT]', $item->getNumber());
						}

						return sprintf('BODY.PEEK[%1$s.MIME] BODY.PEEK[%1$s]', $item->getNumber());
					}
				},
				true
			)
		);

		if (empty($select))
		{
			return array();
		}

		$parts = $this->client->fetch(
			true,
			$dirPath,
			$excerpt['MSG_UID'],
			sprintf('(%s)', join(' ', $select)),
			$error
		);

		if (false === $parts)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		foreach ($rfc822Parts as $item)
		{
			$headerKey = sprintf('BODY[%s.HEADER]', $item->getNumber());
			$bodyKey = sprintf('BODY[%s.TEXT]', $item->getNumber());

			if (array_key_exists($headerKey, $parts) || array_key_exists($bodyKey, $parts))
			{
				$partMime = 'Content-Type: message/rfc822';
				if (!empty($item->getParams()['name']))
				{
					$partMime .= sprintf('; name="%s"', $item->getParams()['name']);
				}

				if (!empty($item->getDisposition()[0]))
				{
					$partMime .= sprintf("\r\nContent-Disposition: %s", $item->getDisposition()[0]);
					if (!empty($item->getDisposition()[1]) && is_array($item->getDisposition()[1]))
					{
						foreach ($item->getDisposition()[1] as $name => $value)
						{
							$partMime .= sprintf('; %s="%s"', $name, $value);
						}
					}
				}

				$parts[sprintf('BODY[%1$s.MIME]', $item->getNumber())] = $partMime;
				$parts[sprintf('BODY[%1$s]', $item->getNumber())] = sprintf(
					"%s\r\n\r\n%s",
					rtrim($parts[$headerKey], "\r\n"),
					ltrim($parts[$bodyKey], "\r\n")
				);

				unset($parts[$headerKey], $parts[$bodyKey]);
			}
		}

		return $parts;
	}

	public function cacheDirs()
	{
		static $lastCacheSession;

		if ($this->session === $lastCacheSession)
		{
			return;
		}

		$dirs = $this->client->listex('', '%', $error);
		if (false === $dirs)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		$list = [];
		foreach ($dirs as $item)
		{
			$parts = explode($item['delim'], $item['name']);

			$item['path'] = $item['name'];
			$item['name'] = end($parts);

			$list[$item['name']] = $item;
		}

		// The removal below must not trust what this process cached about the prepared generations earlier
		GenerationScope::invalidateActivePointerCache((int)$this->mailbox['ID']);

		$this->getDirsHelper()->syncDbDirs($list);

		$lastCacheSession = $this->session;
	}

	public function listDirs($pattern, $useDb = false)
	{
		$dirs = $this->client->listex('', $pattern, $error);
		if (false === $dirs)
		{
			$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());

			return false;
		}

		$list = [];

		foreach ($dirs as $dir)
		{
			$parts = explode($dir['delim'], $dir['name']);

			$dir['path'] = $dir['name'];
			$dir['name'] = end($parts);
			$list[$dir['path']] = $dir;
		}

		return $list;
	}

	public function cacheMeta()
	{
		return $this->getDirsHelper()->getSyncDirs();
	}

	protected function getFolderToMessagesMap($messages)
	{
		if (isset($messages['MSG_UID']))
		{
			$messages = [$messages];
		}
		$data = [];
		$result = new Main\Result();
		foreach ($messages as $message)
		{
			$id = $message['MSG_UID'];
			$folderFrom = $this->getDirsHelper()->getDirPathByHash($message['DIR_MD5']);
			$data[$folderFrom][] = $id;
			$results[$folderFrom][] = $message;
		}
		return $result->setData($data);
	}

	public function markUnseen($messages)
	{
		$result = $this->getFolderToMessagesMap($messages);
		foreach ($result->getData() as $folderFrom => $ids)
		{
			$result = $this->client->unseen($ids, $folderFrom);
			if (!$result->isSuccess() || !$this->client->getErrors()->isEmpty())
			{
				break;
			}
		}
		return $result;
	}

	public function markSeen($messages)
	{
		$result = $this->getFolderToMessagesMap($messages);
		foreach ($result->getData() as $folderFrom => $ids)
		{
			$result = $this->client->seen($ids, $folderFrom);
			if (!$result->isSuccess() || !$this->client->getErrors()->isEmpty())
			{
				break;
			}
		}
		return $result;
	}

	public function moveMailsToFolder($messages, $folderTo)
	{
		$result = $this->getFolderToMessagesMap($messages);
		$moveResult = new Main\Result();
		foreach ($result->getData() as $folderFrom => $ids)
		{
			$moveResult = $this->client->moveMails($ids, $folderFrom, $folderTo);
			if (!$moveResult->isSuccess() || !$this->client->getErrors()->isEmpty())
			{
				break;
			}
		}

		return $moveResult;
	}

	public function deleteMails($messages)
	{
		$result = $this->getFolderToMessagesMap($messages);

		foreach ($result->getData() as $folderName => $messageId)
		{
			$result = $this->client->delete($messageId, $folderName);
		}

		return $result;
	}

	public function syncMailbox()
	{
		/*
			A refused pass reports a count of false, never a bare false: the caller reads
			the report by key, and a bare false answers every key with null - the failure
			disappears and the pass declares itself successful.
		*/
		$refused = [
			'syncCount' => false,
			'reSyncCount' => 0,
			'reSyncStatus' => false,
		];

		// An unknown or stale generation must fail before any IMAP call
		if (!$this->checkSyncGenerationContext())
		{
			return $refused;
		}

		if (!$this->client->authenticate($error))
		{
			$userId = (int)($this->mailbox['USER_ID'] ?? 0);
			$mailboxId = (int)($this->mailbox['ID'] ?? 0);
			if ($userId > 0 && $mailboxId > 0)
			{
				(new MailboxSyncManager($userId))->registerFailedConnection($mailboxId);
			}

			return $refused;
		}

		$syncReport = [
			'syncCount' => 0,
			'reSyncCount' => 0,
			'reSyncStatus' => false,
		];

		$this->cacheDirs();

		$currentDir = null;

		if (!empty($this->syncParams['currentDir']))
		{
			$currentDir = $this->syncParams['currentDir'];
		}

		$dirsSync = $this->getDirsHelper()->getSyncDirsOrderByTime($currentDir);

		if (empty($dirsSync))
		{
			return $syncReport;
		}

		$lastDir = $this->getDirsHelper()->getLastSyncDirOrdered($currentDir);

		foreach ($dirsSync as $item)
		{
			MailboxDirectoryHelper::setCurrentSyncDir($item->getPath());

			$syncReport['syncCount'] += $this->syncDir($item->getPath());

			if ($this->isTimeQuotaExceeded())
			{
				break;
			}

			MailboxDirectory::updateSyncTime($item->getId(), time());

			if ($lastDir != null && $item->getPath() == $lastDir->getPath())
			{
				MailboxDirectoryHelper::setCurrentSyncDir('');
				break;
			}
		}

		$this->setLastSyncResult(['updatedMessages' => 0, 'deletedMessages' => 0]);

		if (!$this->isTimeQuotaExceeded())
		{
			// Mark emails from unsynchronized folders for deletion
			$this->lastSyncResult['deletedMessages'] += $this->unregisterMessagesOutsideSyncDirs();

			if (!empty($this->syncParams['full']))
			{
				foreach ($dirsSync as $item)
				{
					$reSyncReport = $this->resyncDir($item->getPath());

					if($reSyncReport['complete'])
					{
						$syncReport['reSyncCount']++;
					}
					if ($this->isTimeQuotaExceeded())
					{
						break;
					}
				}

				if($syncReport['reSyncCount'] === count($dirsSync))
				{
					$syncReport['reSyncStatus'] = true;
				}
			}
		}

		return $syncReport;
	}

	/**
	 * Rows of the folders left out of the synchronization. Whether a message of such a folder is still in
	 * the mailbox cannot be asked here - the rows lie in folders the check does not open - so what is
	 * checked is the folder itself, and only where the answer can change the decision.
	 *
	 * A folder the user switched off is known to the database and its rows go as they always did. A folder
	 * that is missing from the database is another matter: a LIST that came back short takes the folder out
	 * of the database together with its subfolders, and the rows of a folder that is still on the server
	 * must outlive that. Looking for such rows costs a grouping over every live row of the mailbox and a
	 * listing of the whole server, while the state itself outlives the runs, so it is done on a schedule.
	 */
	protected function unregisterMessagesOutsideSyncDirs(): int
	{
		$eventData = [
			'info' => 'disabled directory synchronization in Bitrix',
		];

		$knownDirsMd5 = array_map(
			static fn ($dir) => md5($dir->getPath(true)),
			array_values($this->getDirsHelper()->getDirs())
		);
		$syncDirsMd5 = array_map('md5', $this->getDirsHelper()->getSyncDirsPath(true));

		$countDeleted = 0;

		$switchedOffDirsMd5 = array_values(array_diff($knownDirsMd5, $syncDirsMd5));
		if (!empty($switchedOffDirsMd5))
		{
			$result = $this->unregisterMessages(['@DIR_MD5' => $switchedOffDirsMd5], $eventData, true);
			$countDeleted += $result ? $result->getCount() : 0;
		}

		$checkedAt = $this->readUnknownDirsCheckedAt();
		if (time() - $checkedAt < self::UNKNOWN_DIRS_CHECK_INTERVAL)
		{
			return $countDeleted;
		}

		/*
			The window is spent by the attempt and not by its success: a listing that fails is not repeated
			until the next window either, and that is the point - a server that answers LIST with an error
			would otherwise be asked again every pass, which is the cost this schedule was put here to avoid.
		*/
		$this->writeUnknownDirsCheckedAt(time(), $checkedAt > 0);

		$vanishedDirsMd5 = $this->selectDirsMd5MissingFromDataBase($knownDirsMd5);
		if (empty($vanishedDirsMd5))
		{
			return $countDeleted;
		}

		$dirsOnServer = $this->listDirs('*');
		if ($dirsOnServer === false)
		{
			// nothing is confirmed while the listing itself fails, the rows wait for the next window
			return $countDeleted;
		}

		$dirsOnServerMd5 = array_map(
			static fn ($path) => md5(Emoji::encode($path)),
			array_keys($dirsOnServer)
		);

		$goneDirsMd5 = array_values(array_diff($vanishedDirsMd5, $dirsOnServerMd5));
		if (empty($goneDirsMd5))
		{
			return $countDeleted;
		}

		$result = $this->unregisterMessages(['@DIR_MD5' => $goneDirsMd5], $eventData, true);

		return $countDeleted + ($result ? $result->getCount() : 0);
	}

	/**
	 * @return array hashes the rows of the mailbox point at while no folder of the database carries them
	 */
	private function selectDirsMd5MissingFromDataBase(array $knownDirsMd5): array
	{
		$filter = Mail\MailMessageUidTable::getPresetRemoveFilters();

		if (!empty($knownDirsMd5))
		{
			$filter['!@DIR_MD5'] = $knownDirsMd5;
		}

		$rows = $this->listMessages([
			'select' => ['DIR_MD5'],
			'filter' => $filter,
			'group' => ['DIR_MD5'],
		]);

		return array_column($rows, 'DIR_MD5');
	}

	protected function readUnknownDirsCheckedAt(): int
	{
		$row = Mail\Internals\MailEntityOptionsTable::getRow([
			'select' => ['VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailbox['ID'],
				'=ENTITY_TYPE' => Mail\Internals\MailEntityOptionsTable::MAILBOX_TYPE_NAME,
				'=ENTITY_ID' => (string)(int)$this->mailbox['ID'],
				'=PROPERTY_NAME' => self::UNKNOWN_DIRS_CHECK_PROPERTY,
			],
		]);

		return (int)($row['VALUE'] ?? 0);
	}

	/** @param bool $isStored - whether the mailbox already carries the marker, known to the caller that read it */
	protected function writeUnknownDirsCheckedAt(int $time, bool $isStored): void
	{
		$mailboxId = (int)$this->mailbox['ID'];

		if (!$isStored)
		{
			Mail\Internals\MailEntityOptionsTable::insertIgnore(
				$mailboxId,
				$mailboxId,
				Mail\Internals\MailEntityOptionsTable::MAILBOX_TYPE_NAME,
				self::UNKNOWN_DIRS_CHECK_PROPERTY,
				(string)$time,
			);

			return;
		}

		Mail\Internals\MailEntityOptionsTable::update(
			$this->getUnknownDirsCheckPrimary(),
			[
				'VALUE' => (string)$time,
				'DATE_INSERT' => new Main\Type\DateTime(),
			],
		);
	}

	private function getUnknownDirsCheckPrimary(): array
	{
		return [
			'MAILBOX_ID' => (int)$this->mailbox['ID'],
			'ENTITY_TYPE' => Mail\Internals\MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			'ENTITY_ID' => (string)(int)$this->mailbox['ID'],
			'PROPERTY_NAME' => self::UNKNOWN_DIRS_CHECK_PROPERTY,
		];
	}

	public function syncDir($dirPath)
	{
		// quickSync enters here directly, the generation is validated before any IMAP call
		if (!$this->checkSyncGenerationContext())
		{
			return false;
		}

		$crmImapGateResult = $this->ensureCrmImapGateReady();
		if (!$crmImapGateResult->isSuccess())
		{
			$this->registerCrmImapGateErrors($crmImapGateResult);

			return false;
		}

		$dir = $this->getDirsHelper()->getDirByPath($dirPath);

		if (!$dir || !$dir->isSync())
		{
			return false;
		}

		if ($dir->isSyncLock() || !$dir->startSyncLock())
		{
			return null;
		}

		$result = $this->syncDirInternal($dir);

		$dir->stopSyncLock();

		$this->lastSyncResult['newMessages'] += $result;

		return $result;
	}

	protected function setIsOldStatusesLowerThan($internalDate, $dirPath, $mailboxId)
	{
		if($internalDate === false)
		{
			return true;
		}

		$dirsHelper = $this->getDirsHelper();
		$dir = $dirsHelper->getDirByPath($dirPath);

		$entity = \Bitrix\Mail\MailMessageUidTable::getEntity();
		$connection = $entity->getConnection();

		$where = sprintf(
			'(%s)',
			Main\Entity\Query::buildFilterSql(
					$entity,
					$this->getGenerationScope()->apply([
						'<=INTERNALDATE' => $internalDate,
						'=DIR_MD5'	=>	$dir->getDirMd5(),
						'=MAILBOX_ID'	=>	$mailboxId,
						'!=IS_OLD' => 'Y',
					])
				)
			);

		$connection->query(sprintf(
			'UPDATE %s SET IS_OLD = "Y", IS_SEEN = "Y" WHERE %s LIMIT 1000',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			$where
		));

		if($connection->getAffectedRowsCount() === 0)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * @param $mailboxID
	 * @param $dirPath
	 * @param $UIDs
	 * @param bool $ignoreSyncFrom - false applies the period boundary while receiving each message
	 * @param bool $stopOnEmptyChunk - true aborts the whole run once a fetch chunk comes back empty.
	 *        Callers passing the entire list in one call must set it to false: aborting would leave the
	 *        tail unsynced while the run still reports success, so the caller would mark the period as
	 *        covered and the tail would never be fetched again.
	 * @param bool $failOnLocalError - false preserves the legacy best-effort behavior
	 * @param array<int, array> $generationPairsByUid Old placements explicitly matched to fetched UIDs.
	 * @return bool - success status: in the fail-on-local-error mode a message left without a body makes
	 *        it false, as it did before the pass learned to go on. A chunk whose list lost letters makes
	 *        it false in every mode {@see MessageSyncPassResult::markListIncomplete()}. A caller that
	 *        needs to know WHICH messages were left behind takes syncMessagesPass() instead. A false of a
	 *        run that reports {@see hasStoppedOnTimeQuota()} is the time of the hit running out and not a
	 *        failure: the uids up to {@see getLastSyncedUid()} are done and the rest is expected on the
	 *        next run, and the same holds for a false left by an incomplete list.
	 * @throws Main\DB\SqlQueryException
	 * @throws Main\SystemException
	 */
	public function syncMessages(
		$mailboxID,
		$dirPath,
		$UIDs,
		$isRecovered = false,
		bool $ignoreSyncFrom = true,
		bool $stopOnEmptyChunk = true,
		bool $failOnLocalError = false,
		array $generationPairsByUid = [],
	)
	{
		$crmImapGateResult = $this->ensureCrmImapGateReady();
		if (!$crmImapGateResult->isSuccess())
		{
			$this->registerCrmImapGateErrors($crmImapGateResult);

			return false;
		}

		$result = $this->syncMessagesPass(
			$mailboxID,
			$dirPath,
			$UIDs,
			$isRecovered,
			$ignoreSyncFrom,
			$stopOnEmptyChunk,
			$failOnLocalError,
			$generationPairsByUid,
		);
		$this->lastDeferredUids = $result->getDeferredUids();
		$this->lastCompletedUids = $result->getCompletedUids();
		$this->lastMessageClientErrorCount = $result->getMessageClientErrorCount();

		return $result->isSucceeded() && $result->getDeferredUids() === [];
	}

	/** @return int[] */
	public function getLastDeferredUids(): array
	{
		return $this->lastDeferredUids;
	}

	/** @return int[] */
	public function getLastCompletedUids(): array
	{
		return $this->lastCompletedUids;
	}

	public function getLastMessageClientErrorCount(): int
	{
		return $this->lastMessageClientErrorCount;
	}

	/**
	 * @see syncMessages() for the parameters; this is the same pass reporting its per-message outcome.
	 *
	 * More outcomes than success and failure: the pass can be given back by the clock, see
	 * {@see MessageSyncPassResult::markStoppedOnTimeQuota()}, and a chunk of it can come back by a list
	 * that lost letters, see {@see MessageSyncPassResult::markListIncomplete()}. A caller that keeps
	 * score per dir has to read both apart from a failure - the list is unfinished, but nothing refused
	 * it.
	 *
	 * @throws Main\DB\SqlQueryException
	 * @throws Main\SystemException
	 */
	protected function syncMessagesPass(
		$mailboxID,
		$dirPath,
		$UIDs,
		$isRecovered = false,
		bool $ignoreSyncFrom = true,
		bool $stopOnEmptyChunk = true,
		bool $failOnLocalError = false,
		array $generationPairsByUid = [],
	): MessageSyncPassResult
	{
		$this->lastSyncedUid = 0;
		$this->stoppedOnTimeQuota = false;

		$result = new MessageSyncPassResult();

		$meta = $this->client->select($dirPath, $error);
		$uidtoken = $meta['uidvalidity'];

		//checking the dir for existence or authentication failed
		if (false === $meta)
		{
			return $result;
		}

		$dirsHelper = $this->getDirsHelper();

		$dir = $dirsHelper->getDirByPath($dirPath);
		if (!$dir)
		{
			$result->markFailed();

			return $result;
		}

		$deliveryBoundary = $this->getDeliveryBoundaryService()->resolve(
			(int)$dir->getId(),
			$dir->getDirMd5(),
			(int)$this->getGenerationContext()->generationId,
			(int)$uidtoken,
			fn () => $this->getServerMaximumUid($dir->getPath(), $meta),
		);

		$chunks = array_chunk($UIDs, 10);
		$retryUids = $failOnLocalError
			? $this->collectRetryUids($dir, (int)$uidtoken, $UIDs)
			: []
		;

		$entity = Mail\MailMessageUidTable::getEntity();
		$connection = $entity->getConnection();

		foreach ($chunks as $chunk)
		{
			$connection->query(sprintf(
				'DELETE FROM %s WHERE %s',
				Mail\MailMessageUidTable::getTableName(),
				Main\Entity\Query::buildFilterSql(
					$entity,
					$this->getGenerationScope()->apply([
						'@MSG_UID' => $chunk,
						'=MESSAGE_ID' => 0,
						'=MAILBOX_ID' => $mailboxID,
						'=DIR_MD5'	=>	$dir->getDirMd5()
					])
				)
			));

			$messages = $this->client->fetch(
				true,
				$dirPath,
				join(',', $chunk),
				'(UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER])',
				$error,
				'list'
			);

			if (empty($messages))
			{
				if ($messages === false)
				{
					$this->warnings->add($this->client->getErrors()->toArray());
					return $result;
				}

				if ($stopOnEmptyChunk)
				{
					break;
				}

				// the whole chunk vanished from the server: skip it, the rest of the list is still expected
				$this->markSyncedUpTo($chunk, $result->getDeferredUids(), $result->getUnvouchedUid());

				continue;
			}

			/*
				The uids of the chunk the answer did bring. An entry the guard below drops proves a loss
				only when the letter it stood for is missing from the answer: an unsolicited response
				about a letter of another number is an entry of its own, it took nothing over, and the
				chunk it arrived with is whole. Counted as a loss, it would hold the cursor of a chunk
				that came back complete - and a mailbox read from a phone brings such responses at every
				turn.
			*/
			$answeredUids = [];

			foreach ($messages as $item)
			{
				if (isset($item['UID']))
				{
					$answeredUids[(int)$item['UID']] = true;
				}
			}

			$chunkAnsweredWhole = array_diff(array_map('intval', $chunk), array_keys($answeredUids)) === [];

			$this->parseHeaders($messages);

			$this->blacklistMessages($dir->getPath(), $messages);

			$this->removeExistingMessagesFromSynchronizationList(
				$dir->getPath(),
				$uidtoken,
				$messages,
				$failOnLocalError,
			);

			if ($this->lastSyncListIncomplete && !$chunkAnsweredWhole)
			{
				/*
					A letter of this chunk was dropped as unusable, and it is a letter and not a stray
					entry: an unsolicited response lands on the entry of the letter of the same sequence
					number and overwrites it. Which uid of the chunk it was cannot be told, and telling it
					by the difference with the chunk would name the letters legitimately gone from the
					server as well, so the whole chunk stays unpassed and comes back on the next run.
				*/
				$result->markListIncomplete((int)min($chunk));
			}

			foreach ($messages as &$message)
			{
				$this->fillMessageFields($message, $dir->getPath(), $uidtoken);
			}

			$this->linkWithExistingMessages($messages);
			$this->linkWithDeclaredGenerationPairs($messages, $generationPairsByUid);

			foreach ($messages as $item)
			{
				$isOutgoing = false;

				if(empty($item['__replaces']))
				{
					$outgoingMessageId = $this->getLocalMessageIdFromHeader($item);

					if($outgoingMessageId !== '')
					{
						$item['__replaces'] = $outgoingMessageId;
						$isOutgoing = true;
					}
				}

				$hashesMap = [];
				$isStorageFailure = false;
				$clientErrorsBeforeMessage = count($this->client->getErrors());
				$deliveryContext = $this->resolveMessageDeliveryContext(
					$item,
					$deliveryBoundary,
					(int)$dir->getId(),
					(int)$uidtoken,
					(int)$item['UID'],
					(bool)$isRecovered,
					$hashesMap,
				);
				$syncResult = $this->runWithDeliveryContext(
					$deliveryContext,
					function () use (
						$dir,
						$item,
						&$hashesMap,
						$ignoreSyncFrom,
						$isOutgoing,
						$isRecovered,
						$failOnLocalError,
						$retryUids,
						&$isStorageFailure,
					) {
						return $this->syncMessage(
							$dir->getPath(),
							$item,
							$hashesMap,
							$ignoreSyncFrom,
							$isOutgoing,
							$isRecovered,
							$failOnLocalError,
							$failOnLocalError && isset($retryUids[(int)$item['UID']]),
							$isStorageFailure,
						);
					},
				);

				if ($failOnLocalError)
				{
					if ($syncResult === false)
					{
						$errorsOnMessage = array_slice(
							$this->client->getErrors()->toArray(),
							$clientErrorsBeforeMessage,
						);
						$isConnectionLost = self::hasTransportError($errorsOnMessage);

						/*
							A message left without a body does not cancel the rest of the list. Errors the
							server raised refusing this very message are charged to it, so that a refusal to
							give one message is not read as a broken connection by the caller. A lost link is
							the opposite case: it belongs to the run, not to whatever message was being read
							at that moment, and costs the message nothing - just as a failure of its own row
							does, which happens before the server is asked for the body at all.
						*/
						$result->deferUid(
							(int)$item['UID'],
							$isConnectionLost ? 0 : count($errorsOnMessage),
							spendsAttempt: !$isConnectionLost && !$isStorageFailure,
						);
					}
					else
					{
						$result->completeUid((int)$item['UID']);
					}
				}

				if ($this->isTimeQuotaExceeded())
				{
					/*
						The staged transfer gives the hit back by the clock, which the pass reports as its
						own outcome: unfinished for the caller, no fault of the dir for whoever keeps score
						per dir. The property carries the same fact to a caller of the bool facade, which
						has no result object to read.
					*/
					$this->stoppedOnTimeQuota = true;
					$this->recalculateLabelCountersForLinkedMessages();
					$result->markStoppedOnTimeQuota();

					return $result;
				}
			}

			$this->markSyncedUpTo($chunk, $result->getDeferredUids(), $result->getUnvouchedUid());
			$this->recalculateLabelCountersForLinkedMessages();
		}

		return $result;
	}

	/**
	 * A caller that has already matched an old placement to an exact UID is more precise than the ordinary
	 * header-hash lookup: a server may change INTERNALDATE or re-encode the header while keeping the letter.
	 *
	 * @param array<int, array> $generationPairsByUid UID => old placement.
	 */
	private function linkWithDeclaredGenerationPairs(array &$messages, array $generationPairsByUid): void
	{
		foreach ($messages as &$message)
		{
			$pair = $generationPairsByUid[(int)($message['UID'] ?? 0)] ?? [];
			if (empty($pair['ID']) || (int)($pair['MESSAGE_ID'] ?? 0) <= 0)
			{
				continue;
			}

			$message['__generation_pair'] = $pair;
			$message['__fields']['MESSAGE_ID'] = (int)$pair['MESSAGE_ID'];
			if (!empty($pair['DATE_INSERT']))
			{
				$message['__created'] = $pair['DATE_INSERT'];
			}
		}
		unset($message);
	}

	/**
	 * The errors the client has reported since it held that many of them - the errors of one
	 * command, when the count was taken right before it.
	 *
	 * A list shorter than it was is the whole list: the client starts a new collection the
	 * moment it drops the link, so exactly the command that lost the connection comes back
	 * with fewer errors than were there before it.
	 *
	 * @return Main\Error[]
	 */
	private function errorsRaisedSince(int $errorCount): array
	{
		$errors = $this->client->getErrors()->toArray();

		return count($errors) > $errorCount ? array_slice($errors, $errorCount) : $errors;
	}

	/**
	 * Tells a failure of the link itself from a refusal of the command that was running: a timeout or a
	 * dropped connection says nothing about the message being read at that moment.
	 *
	 * @param Main\Error[] $errors
	 */
	private static function hasTransportError(array $errors): bool
	{
		foreach ($errors as $error)
		{
			if (in_array((int)$error->getCode(), self::CLIENT_TRANSPORT_ERROR_CODES, true))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The uids of a chunk are done. The progress is kept per chunk and not per message: a
	 * caller continuing from it repeats at most one chunk, and never mistakes a message it
	 * has not reached for one it has.
	 *
	 * The progress stops below the lowest uid the pass cannot speak for, whether a message
	 * was left behind by a local failure or a whole chunk by a list that lost letters. Every
	 * chunk of the pass answers to the same border, so a chunk above the one left unfinished
	 * moves nothing either - the message left behind is what the next run continues from.
	 *
	 * @param int[] $chunk
	 * @param int[] $deferredUids
	 * @param int|null $unvouchedUid {@see MessageSyncPassResult::getUnvouchedUid()}
	 */
	private function markSyncedUpTo(array $chunk, array $deferredUids = [], ?int $unvouchedUid = null): void
	{
		$borders = $deferredUids;

		if ($unvouchedUid !== null)
		{
			$borders[] = $unvouchedUid;
		}

		if ($borders !== [])
		{
			$firstUnfinishedUid = (int)min($borders);
			$chunk = array_filter($chunk, static fn ($uid): bool => (int)$uid < $firstUnfinishedUid);
		}

		if ($chunk !== [])
		{
			$this->lastSyncedUid = max($this->lastSyncedUid, (int)max($chunk));
		}
	}

	/**
	 * The last uid of the folder the previous {@see syncMessages()} finished with, 0 when it
	 * finished none of them.
	 */
	public function getLastSyncedUid(): int
	{
		return $this->lastSyncedUid;
	}

	/**
	 * How many errors the client of this engine has reported since the engine was built.
	 *
	 * A pass over a folder goes on after a chunk the server refused to hand over and still
	 * answers that it kept going, so a caller that has to tell a folder really transferred
	 * from one only reported as transferred takes a snapshot of this before the pass and
	 * compares afterwards. The same proof {@see syncDirHistoryByPeriod()} marks its
	 * coverage by.
	 */
	public function getClientErrorCount(): int
	{
		return count($this->client->getErrors());
	}

	/**
	 * The error codes of the client that are about reaching the server at all - the connection,
	 * the greeting, the login - and not about the command the server then refused.
	 */
	private const TRANSPORT_ERRORS = [
		Mail\Imap::ERR_CONNECT,
		Mail\Imap::ERR_REJECTED,
		Mail\Imap::ERR_COMMUNICATE,
		Mail\Imap::ERR_EMPTY_RESPONSE,
		Mail\Imap::ERR_BAD_SERVER,
		Mail\Imap::ERR_STARTTLS,
		Mail\Imap::ERR_AUTH,
		Mail\Imap::ERR_AUTH_MECH,
		Mail\Imap::ERR_AUTH_OAUTH,
	];

	/**
	 * Whether the client has reported that the server itself could not be reached, as opposed to
	 * having answered a command with a refusal.
	 *
	 * The difference matters to every caller that walks a mailbox command by command: a server
	 * that turns one command down leaves the rest of the walk meaningful, while a connection that
	 * is gone makes every command behind it fail for a reason that has nothing to do with the
	 * letters it names - and counting those as refused letters would end the walk successfully.
	 *
	 * The errors of the client accumulate for as long as the engine lives, so this answers "has
	 * the connection been lost at any point", which is what a caller asking it right after a
	 * failed command means: once the wire is gone, nothing after it succeeds either.
	 */
	public function hasLostTheConnection(): bool
	{
		foreach ($this->client->getErrors() as $error)
		{
			if (in_array((int)$error->getCode(), self::TRANSPORT_ERRORS, true))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The previous {@see syncMessages()} gave the hit back because its time ran out, which
	 * is a matter of pace and not a failure of the synchronization.
	 */
	public function hasStoppedOnTimeQuota(): bool
	{
		return $this->stoppedOnTimeQuota;
	}

	protected function collectRetryUids(
		Mail\Internals\Entity\MailboxDirectory $dir,
		int $uidValidity,
		array $uids,
	): array
	{
		$retryUids = [];
		foreach (array_chunk($uids, 1000) as $chunk)
		{
			$retryRows = $this->loadRetryUidRows($dir, $uidValidity, $chunk);
			while ($retryRow = $retryRows->fetch())
			{
				$retryUids[(int)$retryRow['MSG_UID']] = true;
			}
		}

		return $retryUids;
	}

	protected function loadRetryUidRows(
		Mail\Internals\Entity\MailboxDirectory $dir,
		int $uidValidity,
		array $uids,
	)
	{
		return $this->listMessages(
			[
				'select' => ['MSG_UID'],
				'filter' => $this->getGenerationScope()->apply([
					'@MSG_UID' => $uids,
					'=MESSAGE_ID' => 0,
					'=IS_OLD' => Mail\MailMessageUidTable::DOWNLOADED,
					'=MAILBOX_ID' => $this->mailbox['ID'],
					'=DIR_MD5' => $dir->getDirMd5(),
					'=DIR_UIDV' => $uidValidity,
				]),
			],
			false,
		);
	}

	public function isAuthenticated(): bool
	{
		$dirs = $this->getDirsHelper()->getSyncDirsOrderByTime();

		if (empty($dirs))
		{
			return true;
		}

		foreach ($dirs as $dir)
		{
			if (\Bitrix\Mail\Helper::getImapUnseen($this->mailbox, $dir->getPath()) !== false)
			{
				return true;
			}
		}

		return false;
	}

	public function syncDirForSpecificDay($dirPath, $internalDate)
	{
		if($internalDate === false)
		{
			return true;
		}

		$mailboxID = $this->mailbox['ID'];

		$UIDsOnService = \Bitrix\Mail\Helper::getImapUIDsForSpecificDay($mailboxID, $dirPath, $internalDate);

		return $this->runWithDeliveryContext(
			DeliveryContext::backfill(),
			fn () => $this->syncMessages($mailboxID, $dirPath, $UIDsOnService),
		);
	}

	protected function syncDirInternal($dir)
	{
		$messagesSynced = 0;

		$error = [];
		$meta = $this->client->select($dir->getPath(), $error);

		if (false === $meta)
		{
			$this->warnings->add($this->client->getErrors()->toArray());

			if ($this->client->isExistsDir($dir->getPath(), $error) === false)
			{
				$this->getDirsHelper()->removeDirsLikePath([$dir]);
			}

			return false;
		}

		$this->getDirsHelper()->updateMessageCount($dir->getId(), $meta['exists']);
		$historySyncRequired = $this->isHistorySyncRequired(
			$dir,
			(int)$meta['uidvalidity'],
		);
		// The upper UID is valid only for the UIDVALIDITY observed by the same SELECT.
		$snapshotUidValidity = $historySyncRequired ? (int)$meta['uidvalidity'] : null;
		$maximumUid = $historySyncRequired ? $this->getServerMaximumUid($dir->getPath(), $meta) : null;
		$deliveryBoundary = $this->getDeliveryBoundaryService()->resolve(
			(int)$dir->getId(),
			$dir->getDirMd5(),
			(int)$this->getGenerationContext()->generationId,
			(int)$meta['uidvalidity'],
			fn () => $maximumUid ?? $this->getServerMaximumUid($dir->getPath(), $meta),
		);

		$intervalSynchronizationAttempts = 0;

		while ($range = $this->getSyncRange($dir->getPath(), $uidtoken, $intervalSynchronizationAttempts))
		{
			$syncDown = $range[0] > $range[1];

			if ($syncDown)
			{
				MessageInternalDateHandler::clearStartInternalDate($dir->getMailboxId(), $dir->getDirMd5());
			}

			sort($range);

			$messages = $this->client->fetch(
				true,
				$dir->getPath(),
				join(':', $range),
				'(UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER])',
				$error
			);

			$fetchErrors=$this->client->getErrors();
			$errorReceivingMessages = $fetchErrors->getErrorByCode(210) !== null;
			$failureDueToDataVolume = $fetchErrors->getErrorByCode(104) !== null;

			if (empty($messages))
			{
				if (false === $messages)
				{
					if($errorReceivingMessages && !$failureDueToDataVolume)
					{
						/*
						 	 Skip the intervals where all the messages were broken
						*/
						return $messagesSynced;
					}
					elseif($failureDueToDataVolume && $intervalSynchronizationAttempts < count(self::MAXIMUM_SYNCHRONIZATION_LENGTHS_OF_INTERVALS) - 1 )
					{
						$intervalSynchronizationAttempts++;
						continue;
						/*
							Trying to resynchronize by reducing the interval
						*/
					}
					else
					{
						/*
							Fatal errors in which we cannot perform synchronization
						*/
						$this->warnings->add($fetchErrors->toArray());
						return false;
					}
				}
				break;
			}
			else
			{
				$intervalSynchronizationAttempts = 0;
			}

			$syncDown ? krsort($messages) : ksort($messages);

			$this->parseHeaders($messages);

			$this->blacklistMessages($dir->getPath(), $messages);

			$this->removeExistingMessagesFromSynchronizationList($dir->getPath(), $uidtoken, $messages);

			if (empty($messages) && $this->lastSyncListIncomplete)
			{
				/*
					Every entry of the range was dropped as unusable, so the walk of the range writes no
					row and the range the next turn of the loop asks for is the same one. The clock is only
					looked at while the letters are being stored, and there are none - the hit would spend
					itself asking for that range until the process outside it runs out of time. The dir is
					left for the next hit instead.
				*/
				$this->warnings->add([new Main\Error('imap_range_without_usable_letters')]);

				break;
			}

			foreach ($messages as &$message)
			{
				$this->fillMessageFields($message, $dir->getPath(), $uidtoken);
			}

			$this->linkWithExistingMessages($messages);

			$hashesMap = [];

			//To display new messages(grid reload) until synchronization is complete
			$numberOfMessagesInABatch = 1;
			$numberLeftToFillTheBatch = $numberOfMessagesInABatch;

			foreach ($messages as $item)
			{
				$isOutgoing = false;

				if(empty($item['__replaces']))
				{
					$outgoingMessageId = $this->getLocalMessageIdFromHeader($item);

					if($outgoingMessageId !== '')
					{
						$item['__replaces'] = $outgoingMessageId;
						$isOutgoing = true;
					}
				}

				$deliveryContext = $this->resolveMessageDeliveryContext(
					$item,
					$deliveryBoundary,
					(int)$dir->getId(),
					(int)$meta['uidvalidity'],
					(int)$item['UID'],
					knownMessageIds: $hashesMap,
				);

				if ($this->runWithDeliveryContext(
					$deliveryContext,
					function () use ($dir, $item, &$hashesMap, $isOutgoing) {
						return $this->syncMessage(
							$dir->getPath(),
							$item,
							$hashesMap,
							false,
							$isOutgoing,
						);
					},
				))
				{
					$messagesSynced++;
					if ($deliveryContext->shouldNotify() && $this->isNotificationDir($dir))
					{
						$this->lastSyncResult['newMessageId'] = end($hashesMap);
						$this->lastSyncResult['newMessagesNotify']++;
					}

					$numberLeftToFillTheBatch--;
					if($numberLeftToFillTheBatch === 0 and Main\Loader::includeModule('pull'))
					{
						$numberOfMessagesInABatch *= 2;
						$numberLeftToFillTheBatch = $numberOfMessagesInABatch;
						\CPullWatch::addToStack(
							'mail_mailbox_' . $this->mailbox['ID'],
							[
								'params' => [
									'dir' => $dir->getPath(),
									'mailboxId' => $this->mailbox['ID'],
								],
								'module_id' => 'mail',
								'command' => 'new_message_is_synchronized',
							]
						);
						\Bitrix\Pull\Event::send();
					}
				}

				if ($this->isTimeQuotaExceeded())
				{
					break 2;
				}
			}

			$this->recalculateLabelCountersForLinkedMessages();
		}

		$this->recalculateLabelCountersForLinkedMessages();

		if (false === $range)
		{
			$this->warnings->add($this->client->getErrors()->toArray());

			return false;
		}

		if (!$this->isTimeQuotaExceeded() && $historySyncRequired && $maximumUid !== null)
		{
			$this->syncDirHistoryByPeriod($dir, $maximumUid, $snapshotUidValidity);
		}

		return $messagesSynced;
	}

	private function getServerMaximumUid(string $dirPath, array $meta): ?int
	{
		if (isset($meta['uidnext']))
		{
			return max(0, (int)$meta['uidnext'] - 1);
		}

		if ((int)($meta['exists'] ?? 0) === 0)
		{
			return 0;
		}

		$lastMessage = $this->client->fetch(
			false,
			$dirPath,
			(string)$meta['exists'],
			'(UID)',
			$error,
		);

		return isset($lastMessage['UID']) ? (int)$lastMessage['UID'] : null;
	}

	private function getDeliveryBoundaryService(): DeliveryBoundaryService
	{
		return $this->deliveryBoundaryService ??= new DeliveryBoundaryService(
			(int)$this->mailbox['ID'],
			$this->getHistorySyncLogger(),
		);
	}

	private function resolveDeliveryContext(
		?DeliveryBoundary $boundary,
		int $dirId,
		int $uidValidity,
		int $uid,
		bool $isRecovered = false,
	): DeliveryContext
	{
		if ($this->getGenerationContext()->isMigrationImport())
		{
			return DeliveryContext::migration();
		}

		if ($this->deliveryContextOverride !== null)
		{
			return $this->deliveryContextOverride;
		}

		if ($boundary !== null)
		{
			return $boundary->classify(
				(int)$this->getGenerationContext()->generationId,
				$uidValidity,
				$uid,
				$isRecovered,
			);
		}

		return DeliveryContext::withoutBoundary(
			$this->getDeliveryBoundaryService()->isDirectoryEnabled($dirId)
				|| $this->getDeliveryBoundaryService()->isMailboxEnabled(),
			$isRecovered,
		);
	}

	private function resolveMessageDeliveryContext(
		array $message,
		?DeliveryBoundary $boundary,
		int $dirId,
		int $uidValidity,
		int $uid,
		bool $isRecovered = false,
		array $knownMessageIds = [],
	): DeliveryContext
	{
		return $this->resolveDeliveryContext($boundary, $dirId, $uidValidity, $uid, $isRecovered)->forKnownMessage(
			!empty($message['__fields']['MESSAGE_ID'])
			|| !is_null($message['__replaces'] ?? null)
			|| !empty($knownMessageIds[$message['__fields']['HEADER_MD5'] ?? '']),
		);
	}

	private function runWithDeliveryContext(DeliveryContext $context, callable $callback): mixed
	{
		$previous = $this->deliveryContextOverride;
		$this->deliveryContextOverride = $context;

		try
		{
			return $callback();
		}
		finally
		{
			$this->deliveryContextOverride = $previous;
		}
	}

	private function isNotificationDir(Mail\Internals\Entity\MailboxDirectory $dir): bool
	{
		return !$dir->isTrash() && !$dir->isSpam() && !$dir->isDraft() && !$dir->isOutcome();
	}

	public function resyncDir($dirPath, $numberForResync = false)
	{
		$dir = $this->getDirsHelper()->getDirByPath($dirPath);

		if (!$dir || !$dir->isSync())
		{
			return false;
		}

		$report = [
			'complete' => false,
			'dir' => $dir->getPath(),
			'updated' => -$this->lastSyncResult['updatedMessages'],
			'deleted' => -$this->lastSyncResult['deletedMessages'],
		];

		$result = $this->resyncDirInternal($dir,$numberForResync);

		$report['updated'] += $this->lastSyncResult['updatedMessages'];
		$report['deleted'] += $this->lastSyncResult['deletedMessages'];

		if (false === $result)
		{
			$report['errors'] = $this->client->getErrors()->toArray();
		}
		else
		{
			if($this->isTimeQuotaExceeded())
			{
				$report['errors'] = [
					'isTimeQuotaExceeded'
				];
			}
			elseif (self::RESYNC_DIR_INCOMPLETE_LIST === $result)
			{
				/*
					The walk itself failed no command, so the client holds no error to report: the dir is
					named unfinished by the incompleteness of its own list.
				*/
				$report['errors'] = [
					self::RESYNC_DIR_INCOMPLETE_LIST
				];
			}
			else
			{
				$report['complete'] = true;
			}
		}

		return $report;
	}

	/**
	 * The interval of uids the dir holds on the server: null when it could not be learned, an empty
	 * array when the dir is known to hold nothing at all. The two are told apart because a dir that
	 * answered "no letters" is an answer, while a dir that answered nothing is not.
	 *
	 * @return int[]|null
	 */
	private function getBorderlineUIDs(Mail\Internals\Entity\MailboxDirectory $dir): ?array
	{
		$dirKey = $dir->getDirMd5();

		if (array_key_exists($dirKey, $this->borderlineUIDsByDir))
		{
			return $this->borderlineUIDsByDir[$dirKey];
		}

		$error = [];
		$meta = $this->client->select($dir->getPath(), $error);

		if (!isset($meta['exists']))
		{
			$this->warnings->add($this->client->getErrors()->toArray());
			$this->borderlineUIDsByDir[$dirKey] = null;

			return null;
		}

		if ((int)$meta['exists'] === 0)
		{
			// The dir answered that it holds no letters, and that answer addresses its rows by itself
			$this->borderlineUIDsByDir[$dirKey] = [];

			return [];
		}

		$messagesNumberInTheMailService = $meta['exists'];
		$messages = $this->fetchMessage(sprintf('1,%u', $messagesNumberInTheMailService), $dir->getPath());

		if (empty($messages))
		{
			// The dir does hold letters, yet none of them came back: the borders stay unknown
			$this->borderlineUIDsByDir[$dirKey] = null;

			return null;
		}

		$range = [
			reset($messages)['UID'],
			end($messages)['UID'],
		];

		sort($range);

		//fixed errors of some mail services that give incorrect letter intervals
		if($range[0] === $range[1] && $messagesNumberInTheMailService > 1)
		{
			$this->borderlineUIDsByDir[$dirKey] = null;

			return null;
		}

		$this->borderlineUIDsByDir[$dirKey] = $range;

		return $range;
	}

	/**
	 * The rows of the dir the server does hold, shaped as a filter, or null when the borders of the
	 * dir could not be learned at all: a failed SELECT, an answer without a single usable entry, an
	 * interval the server gave degenerated.
	 *
	 * Unknown borders are not the absence of a restriction. Read as one, an empty filter addresses
	 * every row of the mailbox instead of the rows of one dir. A dir known to hold nothing is the
	 * other matter entirely: there is no interval to name, but the dir itself is named all the same,
	 * and its rows are addressed by it alone.
	 */
	protected function getMessageInFolderFilter(Mail\Internals\Entity\MailboxDirectory $dir): ?array
	{
		$borderlineUIDs = $this->getBorderlineUIDs($dir);

		if ($borderlineUIDs === null)
		{
			return null;
		}

		if ($borderlineUIDs === [])
		{
			return ['=DIR_MD5' => $dir->getDirMd5()];
		}

		return [
			'=DIR_MD5' => $dir->getDirMd5(),
			[
				'LOGIC' => 'AND',
				'>=MSG_UID' => $borderlineUIDs[0],
				'<=MSG_UID' => $borderlineUIDs[1],
			],
		];
	}

	/**
	 * @param string $format
	 * (examples: '%u:%u', '1:*', '1,%u')
	 * @param string $dirPath
	 *
	 * The keys match the 'id' value within each structure. FLAGS are absent from an entry whose
	 * attribute parse broke off after the UID, and a consumer of them reads such a letter as
	 * carrying none:
	 * @return array<int, array{
	 *      id: string,
	 *      UID: string,
	 *      FLAGS?: array<int, string>
	 *  }>|false false when the FETCH command itself failed, as opposed to an empty answer
	 */
	private function fetchMessage(string $format, string $dirPath): array|false
	{
		$this->lastFetchListIncomplete = false;

		$error = [];
		$messages = $this->client->fetch(false, $dirPath, $format, '(UID FLAGS)', $error);

		if (empty($messages))
		{
			if (false === $messages)
			{
				$this->warnings->add($this->client->getErrors()->toArray());

				return false;
			}

			return [];
		}

		/*
			A letter is addressed by its uid, so an entry without one is no letter to work with:
			unsolicited FETCH responses (e.g. flag updates made by a concurrent session) carry no
			uid and may even overwrite the solicited entry of the same sequence number. Dropped
			here, the letter behind such an entry is picked up by the next run.

			Missing FLAGS are a different matter and no reason to drop anything: the letter is
			addressable, and the flags an aborted attribute parse left out are read as none at all.
			Dropping it would mean a letter carrying a label the parse cannot read - a user label
			written in Cyrillic is enough - never reaching the portal, run after run.
		*/
		$dropped = array_filter($messages, static fn ($item) => !isset($item['UID']));
		$messages = array_filter($messages, static fn ($item) => isset($item['UID']));

		/*
			A dropped entry proves a loss only when it stands where a letter of the request stood. An
			unsolicited response about a letter outside the asked-for numbers is an entry of its own: it
			took nothing over, and the list of the request is whole. Counted as a loss, it would keep the
			dir unfinished for as long as a concurrent session keeps reporting flag changes.
		*/
		$this->lastFetchListIncomplete = false;

		foreach (array_keys($dropped) as $sequenceNumber)
		{
			if (self::isSequenceNumberRequested($format, (int)$sequenceNumber))
			{
				$this->lastFetchListIncomplete = true;

				break;
			}
		}

		krsort($messages);

		return $messages;
	}

	/**
	 * Whether the sequence number belongs to the set the FETCH asked for. The formats the walk uses are
	 * its own {@see fetchMessage()}: an interval, a pair of borders, or everything the dir holds.
	 */
	private static function isSequenceNumberRequested(string $format, int $sequenceNumber): bool
	{
		if ($format === '1:*')
		{
			return true;
		}

		if (preg_match('/^(\d+):(\d+)$/', $format, $matches))
		{
			return $sequenceNumber >= (int)$matches[1] && $sequenceNumber <= (int)$matches[2];
		}

		if (preg_match('/^(\d+),(\d+)$/', $format, $matches))
		{
			return $sequenceNumber === (int)$matches[1] || $sequenceNumber === (int)$matches[2];
		}

		// An unknown form of the request is read as asking for everything: the careful side of the answer
		return true;
	}

	/**
	 * @return false|string|null false when the dir could not be walked at all, the incompleteness
	 * marker {@see RESYNC_DIR_INCOMPLETE_LIST} when it was walked by a list that may miss letters,
	 * null when the walk stands behind every decision it took.
	 */
	protected function resyncDirInternal($dir, $numberForResync = false)
	{
		$error = [];
		$meta = $this->client->select($dir->getPath(), $error);

		if (!isset($meta['exists']))
		{
			$this->warnings->add($this->client->getErrors()->toArray());

			return false;
		}

		$uidtoken = $meta['uidvalidity'];
		$this->walkedDirLetterCount = isset($meta['exists']) ? (int)$meta['exists'] : null;

		if ($meta['exists'] > 0)
		{
			if ($uidtoken > 0)
			{
				$this->startGenerationReconcile($dir, (int)$uidtoken);
			}
		}
		else
		{
			if ($this->client->ensureEmpty($dir->getPath(), $error))
			{
				$result = $this->unregisterMessages(
					array(
						'=DIR_MD5' => md5($dir->getPath(true)),
					),
					[
						'info' => 'all messages in the directory have been deleted ',
					]
				);

				$countDeleted = $result ? $result->getCount() : 0;

				$this->lastSyncResult['deletedMessages'] += $countDeleted;
			}

			return;
		}

		$messagesNumberInTheMailService = $meta['exists'];
		$messages = $this->fetchMessage((($messagesNumberInTheMailService > 10000 || $numberForResync !== false) ? sprintf('1,%u', $messagesNumberInTheMailService) : '1:*'), $dir->getPath());
		$walkIncomplete = $this->lastFetchListIncomplete;

		if (empty($messages))
		{
			if (false === $messages)
			{
				return false;
			}

			// The dir holds letters, yet nothing usable came back: there was no walk to call finished
			return $this->resyncDirOutcome($walkIncomplete);
		}

		//interval of messages in the directory
		$range = array(
			reset($messages)['UID'],
			end($messages)['UID'],
		);
		sort($range);

		//fixed errors of some mail services that give incorrect letter intervals
		if($range[0] === $range[1] && $messagesNumberInTheMailService > 1)
		{
			return false;
		}

		/*
			Deleting non-existent messages in the service (not included in the message interval on the
			service). The interval belongs to the current generation of the dir, so uids of the previous one
			are not compared with it: their rows wait for their pair, and whatever finds none is deleted by
			finishGenerationReconcile at the end of the walk.

			A dropped entry may be a border letter overwritten by an unsolicited response, which would
			narrow the interval onto living rows: an incomplete list is not trusted with deletion.
		*/
		if (!$walkIncomplete)
		{
			$result = $this->unregisterMessages(
				array(
					'=DIR_MD5' => md5($dir->getPath(true)),
					'=DIR_UIDV' => $uidtoken,
					'>MSG_UID' => 0,
					array(
						'LOGIC'    => 'OR',
						'<MSG_UID' => $range[0],
						'>MSG_UID' => $range[1],
					),
				),
				[
					'info' => 'optimized deletion of non-existent messages',
				]
			);

			$countDeleted = $result ? $result->getCount() : 0;

			$this->lastSyncResult['deletedMessages'] += $countDeleted;
		}

		//resynchronizing a certain number of messages
		if($numberForResync !== false)
		{
			$range1 = $meta['exists'];
			$range0 = max($range1 - ($numberForResync - 1), 1);
			$messages = $this->fetchMessage(sprintf('%u:%u', $range0, $range1), $dir->getPath());
			$walkIncomplete = $walkIncomplete || $this->lastFetchListIncomplete;

			if (empty($messages))
			{
				// A failed FETCH must not pass for a resynced dir
				return (false === $messages ? false : $this->resyncDirOutcome($walkIncomplete));
			}

			$this->resyncWalkIncomplete = $walkIncomplete;
			$this->resyncMessages($dir->getPath(true), $uidtoken, $messages);

			return $this->resyncDirOutcome($walkIncomplete);
		}

		if (!($meta['exists'] > 10000))
		{
			$this->resyncWalkIncomplete = $walkIncomplete;
			$this->resyncMessages($dir->getPath(true), $uidtoken, $messages);

			// An incomplete walk must not settle the reconcile: unpaired rows wait for a complete one
			if (!$walkIncomplete)
			{
				$this->finishGenerationReconcile($dir, (int)$uidtoken);
			}

			return $this->resyncDirOutcome($walkIncomplete);
		}

		$range1 = $meta['exists'];
		while ($range1 > 0)
		{
			$rangeSize = $range1 > 10000 ? 8000 : $range1;
			$range0 = max($range1 - $rangeSize, 1);

			$messages = $this->fetchMessage(sprintf('%u:%u', $range0, $range1), $dir->getPath());
			$walkIncomplete = $walkIncomplete || $this->lastFetchListIncomplete;

			if (empty($messages))
			{
				// A failed FETCH must not pass for a resynced dir
				return (false === $messages ? false : $this->resyncDirOutcome($walkIncomplete));
			}

			$this->resyncWalkIncomplete = $walkIncomplete;
			$this->resyncMessages($dir->getPath(true), $uidtoken, $messages);

			if ($this->isTimeQuotaExceeded())
			{
				return $this->resyncDirOutcome($walkIncomplete);
			}

			$range1 -= $rangeSize;
		}

		// An incomplete walk must not settle the reconcile: unpaired rows wait for a complete one
		if (!$walkIncomplete)
		{
			$this->finishGenerationReconcile($dir, (int)$uidtoken);
		}

		return $this->resyncDirOutcome($walkIncomplete);
	}

	/**
	 * The answer of a walk that reached its end: null for a dir that can be called resynced, the
	 * incompleteness marker for a dir walked by a list that may miss letters.
	 */
	private function resyncDirOutcome(bool $walkIncomplete): ?string
	{
		return $walkIncomplete ? self::RESYNC_DIR_INCOMPLETE_LIST : null;
	}

	/**
	 * The dir answers with a UIDVALIDITY the stored rows do not carry: every uid of the previous generation
	 * is void, while the letters behind them are the very same letters. The rows are left where they are
	 * and the dir is marked for reconciliation - the rows of the new generation take the letters over one
	 * by one as they are registered, and whatever a full walk of the dir does not reach is deleted at its
	 * end.
	 */
	protected function startGenerationReconcile(Mail\Internals\Entity\MailboxDirectory $dir, int $uidValidity): void
	{
		if (!$this->hasRowsOfPreviousGeneration($dir, $uidValidity))
		{
			return;
		}

		$storedMarker = $this->readGenerationReconcileMarker($dir);
		$marker = static::buildGenerationReconcileMarkerValue($uidValidity);

		if ($storedMarker === $marker)
		{
			return;
		}

		// a marker of another generation is overwritten: the reconciliation starts over from the new one
		$this->writeGenerationReconcileMarker($dir, $marker, $storedMarker !== null);

		// the rows of the dir are being replaced, so the cached date of its earliest letter is stale
		MessageInternalDateHandler::clearStartInternalDate($dir->getMailboxId(), $dir->getDirMd5());
	}

	private function hasRowsOfPreviousGeneration(Mail\Internals\Entity\MailboxDirectory $dir, int $uidValidity): bool
	{
		$rows = $this->listMessages([
			'select' => ['ID'],
			'filter' => [
				'=DIR_MD5' => md5($dir->getPath(true)),
				'>DIR_UIDV' => 0,
				'!=DIR_UIDV' => $uidValidity,
				'==DELETE_TIME' => 0,
			],
			'limit' => 1,
		]);

		return !empty($rows);
	}

	/**
	 * The whole dir has just been walked, so a row of the previous generation that no new row took over
	 * holds a letter the server no longer answers for. The deletion goes the guarded way: a server that
	 * keeps answering for the old uids - against RFC 3501 - leaves its rows alive, and the marker stays
	 * until they find their pair.
	 */
	protected function finishGenerationReconcile(Mail\Internals\Entity\MailboxDirectory $dir, int $uidValidity): void
	{
		if ($uidValidity <= 0 || $this->isTimeQuotaExceeded())
		{
			return;
		}

		if ($this->readGenerationReconcileMarker($dir) !== static::buildGenerationReconcileMarkerValue($uidValidity))
		{
			return;
		}

		/*
			The walk of the dir reads numbers and flags, while the letters behind them are read back portion
			by portion over as many runs as it takes. A row is taken over by the row of the new version only
			when its letter is read back, so deleting the remainder before the dir has been read back whole
			would delete the letters that simply have not had their turn yet.
		*/
		if (!$this->isDirReadBackWhole($dir, $uidValidity))
		{
			return;
		}

		$keptAliveRows = false;
		$result = $this->unregisterMessages(
			[
				'=DIR_MD5' => md5($dir->getPath(true)),
				'>DIR_UIDV' => 0,
				'!=DIR_UIDV' => $uidValidity,
			],
			[
				'info' => 'the directory has been deleted',
			],
			false,
			$keptAliveRows,
		);

		$this->lastSyncResult['deletedMessages'] += $result ? $result->getCount() : 0;

		if (!$keptAliveRows)
		{
			$this->deleteGenerationReconcileMarker($dir);
		}
	}

	/**
	 * Whether the dir has been read back whole under that version of it - shown, and not assumed.
	 *
	 * The mark of the history sync says it for a mailbox that has a period of its own: it is written once
	 * the search over that period has nothing left to fetch. A mailbox that syncs without a period leaves
	 * no such mark - its letters come back by the descent over the numbers, which reports nothing of its
	 * own completeness - and an unproven completeness is answered with no. The remainder of such a dir
	 * stays where it stood, rather than being deleted on an assumption.
	 */
	private function isDirReadBackWhole(Mail\Internals\Entity\MailboxDirectory $dir, int $uidValidity): bool
	{
		$boundary = $this->getMinimumSyncDate();

		if ($boundary === false)
		{
			return $this->isDirCoveredByItsRows($dir, $uidValidity);
		}

		return static::isHistoryCoverageMarkerActual(
			$this->readHistoryCoverageMarker($dir),
			$uidValidity,
			(int)$boundary,
		);
	}

	/**
	 * The completeness a mailbox syncing without a period can show: the dir holds a row of the version it
	 * carries now for every letter the server says is in it. Its letters come back by the descent over the
	 * numbers, which leaves no mark of its own, while the number of the letters is what the server answered
	 * when the dir was opened for the walk that has just ended.
	 *
	 * Counted the careful way, so that everything unclear keeps the remainder where it is: a walk that
	 * never learned the number of the letters, and a dir holding more letters than it has rows, are both
	 * answered with no.
	 */
	private function isDirCoveredByItsRows(Mail\Internals\Entity\MailboxDirectory $dir, int $uidValidity): bool
	{
		$lettersInTheDir = $this->walkedDirLetterCount;

		if ($lettersInTheDir === null)
		{
			return false;
		}

		$rowsOfTheCurrentVersion = Mail\MailMessageUidTable::getCount(
			$this->getGenerationScope()->apply([
				'=MAILBOX_ID' => $this->mailbox['ID'],
				'=DIR_MD5' => md5($dir->getPath(true)),
				'=DIR_UIDV' => $uidValidity,
				'>MSG_UID' => 0,
				'>MESSAGE_ID' => 0,
				'==DELETE_TIME' => 0,
			])
		);

		return $rowsOfTheCurrentVersion >= $lettersInTheDir;
	}

	protected function readGenerationReconcileMarker(Mail\Internals\Entity\MailboxDirectory $dir): ?string
	{
		$row = Mail\Internals\MailEntityOptionsTable::getRow([
			'select' => ['VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailbox['ID'],
				'=ENTITY_TYPE' => Mail\Internals\MailEntityOptionsTable::DIR_TYPE_NAME,
				'=ENTITY_ID' => $dir->getId(),
				'=PROPERTY_NAME' => self::GENERATION_RECONCILE_PROPERTY,
			],
		]);

		return $row['VALUE'] ?? null;
	}

	/** @param bool $isStored - whether the dir already carries a marker, known to the caller that read it */
	private function writeGenerationReconcileMarker(
		Mail\Internals\Entity\MailboxDirectory $dir,
		string $value,
		bool $isStored,
	): void
	{
		if (!$isStored)
		{
			// the insert of a parallel run of the same mailbox is dropped: it writes the same generation
			Mail\Internals\MailEntityOptionsTable::insertIgnore(
				(int)$this->mailbox['ID'],
				(string)$dir->getId(),
				Mail\Internals\MailEntityOptionsTable::DIR_TYPE_NAME,
				self::GENERATION_RECONCILE_PROPERTY,
				$value,
			);

			return;
		}

		Mail\Internals\MailEntityOptionsTable::update(
			$this->getGenerationReconcileMarkerPrimary($dir),
			[
				'VALUE' => $value,
				'DATE_INSERT' => new Main\Type\DateTime(),
			],
		);
	}

	private function deleteGenerationReconcileMarker(Mail\Internals\Entity\MailboxDirectory $dir): void
	{
		Mail\Internals\MailEntityOptionsTable::delete($this->getGenerationReconcileMarkerPrimary($dir));
	}

	private function getGenerationReconcileMarkerPrimary(Mail\Internals\Entity\MailboxDirectory $dir): array
	{
		return [
			'MAILBOX_ID' => (int)$this->mailbox['ID'],
			'ENTITY_TYPE' => Mail\Internals\MailEntityOptionsTable::DIR_TYPE_NAME,
			'ENTITY_ID' => (string)$dir->getId(),
			'PROPERTY_NAME' => self::GENERATION_RECONCILE_PROPERTY,
		];
	}

	protected static function buildGenerationReconcileMarkerValue(int $uidValidity): string
	{
		return sprintf('reconcile_%u', $uidValidity);
	}

	protected function parseHeaders(&$messages)
	{
		foreach ($messages as $id => $item)
		{
			$messages[$id]['__header'] = \CMailMessage::parseHeader($item['BODY[HEADER]'], $this->mailbox['LANG_CHARSET']);
			$messages[$id]['__from'] = array_unique(array_map(
				'mb_strtolower',
				array_filter(
					array_merge(
						\CMailUtil::extractAllMailAddresses($messages[$id]['__header']->getHeader('FROM')),
						\CMailUtil::extractAllMailAddresses($messages[$id]['__header']->getHeader('REPLY-TO'))
					),
					'trim'
				)
			));
		}
	}

	protected function blacklistMessages($dirPath, &$messages)
	{
		$trashDir = $this->getDirsHelper()->getTrashPath();
		$spamDir = $this->getDirsHelper()->getSpamPath();

		$targetDir = $spamDir ?: $trashDir ?: null;
		$dir = $this->getDirsHelper()->getDirByPath($dirPath);

		if (empty($targetDir) || ($dir && ($dir->isTrash() || $dir->isSpam())))
		{
			return;
		}

		$blacklist = array(
			'email'  => array(),
			'domain' => array(),
		);

		$blacklistEmails = Mail\BlacklistTable::query()
			->addSelect('*')
			->setFilter(array(
				'=SITE_ID' => $this->mailbox['LID'],
				array(
					'LOGIC'       => 'OR',
					'=MAILBOX_ID' => $this->mailbox['ID'],
					array(
						'=MAILBOX_ID' => 0,
						'@USER_ID'    => array(0, $this->mailbox['USER_ID']),
					),
				),
			))
			->exec()
			->fetchCollection();
		foreach ($blacklistEmails as $blacklistEmail)
		{
			if ($blacklistEmail->isDomainType())
			{
				$blacklist['domain'][] = $blacklistEmail;
			}
			else
			{
				$blacklist['email'][] = $blacklistEmail;
			}
		}

		if (empty($blacklist['email']) && empty($blacklist['domain']))
		{
			return;
		}

		$targetMessages = [];
		$emailAddresses = array_map(function ($element)
		{
			/** @var Mail\Internals\Entity\BlacklistEmail $element */
			return $element->getItemValue();
		}, $blacklist['email']);
		$domains = array_map(function ($element)
		{
			/** @var Mail\Internals\Entity\BlacklistEmail $element */
			return $element->getItemValue();
		}, $blacklist['domain']);

		foreach ($messages as $id => $item)
		{
			if (!empty($blacklist['email']))
			{
				if (array_intersect($messages[$id]['__from'], $emailAddresses))
				{
					$targetMessages[$id] = $item['UID'];

					continue;
				}
				else
				{
					foreach ($blacklist['email'] as $blacklistMail)
					{
						/** @var Mail\Internals\Entity\BlacklistEmail $blacklistMail */
						if (array_intersect($messages[$id]['__from'], [$blacklistMail->convertDomainToPunycode()]))
						{
							$targetMessages[$id] = $item['UID'];
							continue;
						}
					}
				}
			}

			if (!empty($blacklist['domain']))
			{
				foreach ($messages[$id]['__from'] as $email)
				{
					$domain = mb_substr($email, mb_strrpos($email, '@'));
					if (in_array($domain, $domains))
					{
						$targetMessages[$id] = $item['UID'];

						continue 2;
					}
				}
			}
		}

		if (!empty($targetMessages))
		{
			if ($this->client->moveMails($targetMessages, $dirPath, $targetDir)->isSuccess())
			{
				$messages = array_diff_key($messages, $targetMessages);
			}
		}
	}

	protected function buildMessageIdForDataBase($dirPath, $uidToken, $UID): string
	{
		// The initial generation keeps the historical formula, old uid ids never change
		return UidIdentity::build($this->getGenerationContext()->getUidFormulaGenerationId(), $dirPath, $uidToken, $UID);
	}

	protected function buildMessageHeaderHashForDataBase($message): string
	{
		return md5(sprintf(
			'%s:%s:%u',
			trim($message['BODY[HEADER]']),
			$message['INTERNALDATE'],
			$message['RFC822.SIZE']
		));
	}


	protected function removeExistingMessagesFromSynchronizationList(
		$dirPath,
		$uidToken,
		&$messages,
		bool $onlyCompleted = false,
	)
	{
		$existingMessagesId = [];
		$this->lastSyncListIncomplete = false;

		// An entry without a uid stands for no letter the sync could address: drop it upfront
		foreach ($messages as $id => $item)
		{
			if (!isset($item['UID']))
			{
				unset($messages[$id]);
				$this->lastSyncListIncomplete = true;
			}
		}

		if (empty($messages))
		{
			return;
		}

		$range = array(
			reset($messages)['UID'],
			end($messages)['UID'],
		);
		sort($range);

		$filter = $this->getGenerationScope()->apply(array(
			'=DIR_MD5'  => md5(Emoji::encode($dirPath)),
			'=DIR_UIDV' => $uidToken,
			'>=MSG_UID' => $range[0],
			'<=MSG_UID' => $range[1],
			'==DELETE_TIME' => 0,
		));

		if ($onlyCompleted)
		{
			$filter['>MESSAGE_ID'] = 0;
			$filter['=IS_OLD'] = Mail\MailMessageUidTable::RECENT;
		}

		$result = $this->listMessages(array(
			'select' => [
				'ID'
			],
			'filter' => $filter,
		), false);

		while ($item = $result->fetch())
		{
			$existingMessagesId[] = $item['ID'];
		}

		foreach ($messages as $id => $item)
		{
			$messageUid = $this->buildMessageIdForDataBase($dirPath, $uidToken, $item['UID']);

			if (in_array($messageUid, $existingMessagesId))
			{
				unset($messages[$id]);
				continue;
			}

			//We also remove duplicate messages
			$existingMessagesId[] = $messageUid;
		}
	}

	/**
	 * The declared read outside the generation scope of the run: the header hash carries the logical
	 * identity of a letter, and a letter keeps its identity across a change of the source of the
	 * mailbox - scoping this lookup to the active generation would give the same letter a new logical
	 * record after the change, losing what the portal has bound to it. The generation of the row comes
	 * back with it, so the verdicts made on the answer decide for themselves what a row of another
	 * generation is worth.
	 *
	 * The generations the mailbox is preparing are the one exception, and this is the only condition
	 * the read carries. Their rows are hidden from the user until the switch and their letters are
	 * decided by the matching of the migration alone, so a real delivery must not take a logical
	 * identity out of one of them: {@see syncMessage()} saves nothing for a letter that already has an
	 * identity, and the delivery would pass in silence - no filters, no event of a new letter, no CRM
	 * activity and no notification, on a letter the mailbox really did receive.
	 */
	protected function searchExistingMessagesByHeaderInDataBase($headerHashes)
	{
		return $this->listMessages([
			'select' => [
				'ID',
				'HEADER_MD5',
				'MESSAGE_ID',
				'DATE_INSERT',
				'DIR_MD5',
				'DIR_UIDV',
				'DELETE_TIME',
				'GENERATION_ID',
			],
			'filter' => GenerationScope::withoutPreparedGenerations((int)$this->mailbox['ID'])->apply([
				'@HEADER_MD5' => $headerHashes,
			]),
		], false, false);
	}

	protected function searchExistingMessagesByIdInDataBase($idsForDataBase)
	{
		return $this->listMessages(array(
			'select' => array('ID', 'MESSAGE_ID', 'DATE_INSERT'),
			'filter' => array(
				'@ID' => array_values($idsForDataBase),
				'==DELETE_TIME' => 0,
			),
		), false);
	}

	protected function linkWithExistingMessages(&$messages)
	{
		/*
			The header hash is not bound to a generation, so it could link an imported
			uid to a message of the previous source before the matcher ever looked at
			it - and then no matching result would be written down. In an imported
			generation the local identity of a letter is chosen by the matcher alone.
		*/
		if ($this->getGenerationContext()->isMigrationImport())
		{
			return;
		}

		$hashes = [];
		$idsForDataBase = [];

		foreach ($messages as $id => $item)
		{
			$hashes[$id] = $item['__fields']['HEADER_MD5'];
			$idsForDataBase[$id] = $item['__fields']['ID'];
		}

		$hashesMap = [];

		foreach ($hashes as $id => $hash)
		{
			if (!array_key_exists($hash, $hashesMap))
			{
				$hashesMap[$hash] = [];
			}

			$hashesMap[$hash][] = $id;
		}

		$existingMessages = $this->searchExistingMessagesByHeaderInDataBase(array_keys($hashesMap));

		/*
			For example, Gmail's labels act like "tags".
			Any individual email message can have multiple labels,
			and thus appear under multiple dirs.
		*/
		while ($item = $existingMessages->fetch())
		{
			foreach ((array)$hashesMap[$item['HEADER_MD5']] as $id)
			{
				$messages[$id]['__created'] = $item['DATE_INSERT'];
				$messages[$id]['__fields']['MESSAGE_ID'] = $item['MESSAGE_ID'];

				if ($this->isRowOfPreviousGeneration($item, $messages[$id]['__fields']))
				{
					$messages[$id]['__generation_pair'] = $item;
				}
			}
		}

		$existingMessages = $this->searchExistingMessagesByIdInDataBase($idsForDataBase);

		/*
			To restore messages stored with "broken" directories.
			For example, previously, data for messages in directories containing emojis were stored incorrectly in the database.
		*/
		while ($item = $existingMessages->fetch())
		{
			$id = array_search($item['ID'], $idsForDataBase);
			$messages[$id]['__created'] = $item['DATE_INSERT'];
			$messages[$id]['__fields']['MESSAGE_ID'] = $item['MESSAGE_ID'];
			$messages[$id]['__replaces'] = $item['ID'];
		}
	}

	/**
	 * The same letter of the same dir under the previous UIDVALIDITY of that dir: the uid its row carries
	 * is void and the row being registered now replaces it. A row of another dir with the same header hash
	 * is a label of the mail service - Gmail shows one letter in several dirs - and it stays where it is,
	 * giving the new row nothing but the id of the letter, as it did before.
	 *
	 * A row outside the generation scope of this run is refused before any UIDVALIDITY is compared: the
	 * numbers of two sources of one mailbox are issued independently, so a row of a retained generation may
	 * carry a smaller one without being an earlier epoch of anything. Refusing it outright is what keeps the
	 * comparison meaning what the module has always meant by it - two epochs of one and the same source.
	 */
	protected function isRowOfPreviousGeneration(array $storedRow, array $fields): bool
	{
		if (!$this->peekGenerationScope()->includes((int)($storedRow['GENERATION_ID'] ?? 0)))
		{
			return false;
		}

		return (string)($storedRow['DIR_MD5'] ?? '') === (string)($fields['DIR_MD5'] ?? '')
			// an outgoing row awaits its uid from the mail service and belongs to no generation at all
			&& (int)($storedRow['DIR_UIDV'] ?? 0) > 0
			&& (int)($storedRow['DIR_UIDV'] ?? 0) !== (int)($fields['DIR_UIDV'] ?? 0)
			// a row already marked for deletion awaits the ordinary cleanup and is no pair for anything
			&& (int)($storedRow['DELETE_TIME'] ?? 0) === 0
		;
	}

	/**
	 * The letter and the arrival date of the pair are given to the row of the new generation before it is
	 * registered, so the insert carries them from the start and no second write follows it. A row that
	 * conflicts with a living one keeps what that one holds: the merge set of the registration leaves both
	 * fields alone, and they belong to the portal rather than to the answer of the server.
	 */
	protected function carryOverGenerationPair(array $message, array &$fields): void
	{
		$pair = $message['__generation_pair'] ?? [];

		if (empty($pair['ID']) || $pair['ID'] === $fields['ID'])
		{
			return;
		}

		if ((int)$pair['MESSAGE_ID'] <= 0)
		{
			/*
				The body of the pair never arrived, so there is no letter to inherit and no arrival to date
				back to: the new row needs its own moment of insertion to be given the usual few minutes for
				the body before the cleanup of underloaded rows reaches it.
			*/
			return;
		}

		if ((int)$fields['MESSAGE_ID'] <= 0)
		{
			$fields['MESSAGE_ID'] = $pair['MESSAGE_ID'];
		}

		// the letter has not just arrived: it came when the row of the previous generation was inserted
		if (!empty($pair['DATE_INSERT']))
		{
			$fields['DATE_INSERT'] = $pair['DATE_INSERT'];
		}
	}

	/**
	 * The pair leaves the registry once the row of the new generation holds its letter. The letter itself
	 * does not leave the mailbox, so the row is dropped directly instead of going the deletion way with its
	 * cascades of marks, labels and events.
	 *
	 * A pair holding another letter than the registered row ended up with is left where it is: dropping it
	 * would take the last row of that letter with it, and there are mailboxes whose rows share one header
	 * hash while pointing at letters of their own. Such a pair is for the remainder of the walk to decide.
	 * Which letter the row ended up with is asked of the registry and not of the prepared fields, because a
	 * registration meeting a row of its own keeps the letter of that row without saying so.
	 */
	protected function takeOverGenerationPair(array $message, array $fields): void
	{
		$pair = $message['__generation_pair'] ?? [];

		if (empty($pair['ID']) || $pair['ID'] === $fields['ID'])
		{
			return;
		}

		$messageId = $this->readRegisteredMessageId((string)$fields['ID']);

		if ($messageId <= 0 || $messageId !== (int)$pair['MESSAGE_ID'])
		{
			return;
		}

		$this->deleteReplacedGenerationRow($pair['ID']);

		if (Mail\Helper\Label\LabelsFeature::isEnabled())
		{
			$this->linkedMessageIds[] = $messageId;
		}
	}

	/**
	 * The letter the row of the new generation really ended up holding. The registration meeting a row of its
	 * own in the registry keeps the letter of that row - a row marked for deletion under the new generation
	 * among them - and the prepared fields know nothing of it, while the pair may only be dropped once its
	 * letter is held by a row that stays.
	 */
	protected function readRegisteredMessageId(string $rowId): int
	{
		$row = Mail\MailMessageUidTable::getRow([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=ID' => $rowId,
				'=MAILBOX_ID' => $this->mailbox['ID'],
			],
		]);

		return (int)($row['MESSAGE_ID'] ?? 0);
	}

	/**
	 * The generation scope belongs in the condition as well, even though the verdict of the pair has already
	 * refused every row outside it: this DELETE goes past the deletion queue, the events and the cascades,
	 * and the ordinary diagnostics of deletions does not see it at all. One condition here is what keeps a
	 * later mistake of a caller from reaching the rows of a retained generation.
	 */
	protected function deleteReplacedGenerationRow(string $rowId): void
	{
		$entity = Mail\MailMessageUidTable::getEntity();
		$connection = $entity->getConnection();

		$connection->query(sprintf(
			'DELETE FROM %s WHERE %s',
			Mail\MailMessageUidTable::getTableName(),
			Main\Entity\Query::buildFilterSql(
				$entity,
				$this->peekGenerationScope()->apply([
					'=ID' => $rowId,
					'=MAILBOX_ID' => $this->mailbox['ID'],
				])
			)
		));
	}

	protected function fillMessageFields(&$message, $dirPath, $uidToken)
	{
		$internalDate = \DateTime::createFromFormat(
			'j-M-Y H:i:s O',
			ltrim(trim($message['INTERNALDATE']), '0')
		);

		// The substituted current moment keeps INTERNALDATE and the sync date cutoff working,
		// but it must not let an unparsed arrival date pass for a fresh one
		$message['__internaldate_parsed'] = $internalDate !== false;
		$message['__internaldate'] = Main\Type\DateTime::createFromPhp($internalDate ?: new \DateTime);

		$message['__fields'] = [
			'ID'           => $this->buildMessageIdForDataBase($dirPath, $uidToken, $message['UID']),
			'DIR_MD5'      => md5(Emoji::encode($dirPath)),
			'DIR_UIDV'     => $uidToken,
			'MSG_UID'      => $message['UID'],
			'INTERNALDATE' => $message['__internaldate'],
			'IS_SEEN'      => (isset($message['FLAGS']) && preg_grep('/^ \x5c Seen $/ix', $message['FLAGS'])) ? 'Y' : 'N',
			'HEADER_MD5'   => $this->buildMessageHeaderHashForDataBase($message),
			'MESSAGE_ID'   => 0,
		];
	}

	protected function isFreshArrival(array $message): bool
	{
		return !empty($message['__internaldate_parsed'])
			&& $message['__internaldate']->getTimestamp() >= time() - self::FRESH_ARRIVAL_WINDOW
		;
	}

	/**
	 * The placement our own send path named in the letter when it uploaded it, which a later read
	 * of that letter answers by replacing that very row instead of registering a new one.
	 *
	 * An imported generation is refused the whole mechanism. The header is an ordinary one and
	 * travels with a copy of the letter, so a transfer that carries our sent mail over to the new
	 * source brings it along - and the row it names belongs to the source the mailbox is still
	 * being served by. Answered there, the read would rewrite the coordinates of a living
	 * placement of the active generation with the coordinates of the new one (the lookup of the
	 * replacement carries no condition on the generation, and the fields it writes carry none
	 * either), hand the imported placement the identity of that letter and leave the matching with
	 * nothing to decide and no result to write down. Our own tail append strips the header for the
	 * same reason {@see TailMessageBuilder}; a letter the transfer brought is stripped of nothing,
	 * so the refusal belongs here.
	 */
	protected function getLocalMessageIdFromHeader($message): string
	{
		if ($this->getGenerationContext()->isMigrationImport())
		{
			return '';
		}

		if (preg_match('/X-Bitrix-Mail-Message-UID:\s*([a-f0-9]+)/i', $message['BODY[HEADER]'], $matches))
		{
			return $matches[1];
		}

		return '';
	}

	/**
	 * Whether the list of the walk may miss letters is taken from $resyncWalkIncomplete and not from a
	 * parameter: the signature is the one an inheritor outside the repository may override, and a new
	 * parameter would make its declaration incompatible with this one.
	 */
	protected function resyncMessages($dirPath, $uidtoken, &$messages)
	{
		$walkIncomplete = $this->resyncWalkIncomplete;

		$excerpt = array();

		$range = array(
			reset($messages)['UID'],
			end($messages)['UID'],
		);
		sort($range);

		$result = $this->listMessages(array(
			'select' => array('ID', 'MESSAGE_ID', 'IS_SEEN'),
			'filter' => $this->getGenerationScope()->apply(array(
				'=DIR_MD5'  => md5($dirPath),
				'=DIR_UIDV' => $uidtoken,
				'>=MSG_UID' => $range[0],
				'<=MSG_UID' => $range[1],
			)),
		), false);

		while ($item = $result->fetch())
		{
			$item['MAILBOX_USER_ID'] = $this->mailbox['USER_ID'];
			$excerpt[$item['ID']] = $item;
		}

		$update = array(
			'Y' => array(),
			'N' => array(),
			'S' => array(),
			'U' => array(),
		);

		foreach ($messages as $id => $item)
		{
			$messageUid = $this->buildMessageIdForDataBase($dirPath, $uidtoken, $item['UID']);

			if (array_key_exists($messageUid, $excerpt))
			{
				/*
					An entry whose attribute parse broke off after the uid brings no flags, and no flags
					is no statement about the letter: the state stored for it is left as it is, on either
					side. The row is claimed by the letter all the same - it is in the dir, it just came
					without its flags.
				*/
				if (isset($item['FLAGS']))
				{
					$excerptSeen = $excerpt[$messageUid]['IS_SEEN'];
					$excerptSeenYN = in_array($excerptSeen, array('Y', 'S')) ? 'Y' : 'N';
					$messageSeen = preg_grep('/^ \x5c Seen $/ix', $item['FLAGS']) ? 'Y' : 'N';

					if ($messageSeen != $excerptSeen)
					{
						if (in_array($excerptSeen, array('S', 'U')))
						{
							$excerpt[$messageUid]['IS_SEEN'] = $excerptSeenYN;
							$update[$excerptSeenYN][$messageUid] = $excerpt[$messageUid];

							if ($messageSeen != $excerptSeenYN)
							{
								$update[$excerptSeen][] = $item['UID'];
							}
						}
						else
						{
							$excerpt[$messageUid]['IS_SEEN'] = $messageSeen;
							$update[$messageSeen][$messageUid] = $excerpt[$messageUid];
						}
					}
				}

				unset($excerpt[$messageUid]);
			}
			else
			{
				static $cache;

				$scope = $this->getGenerationScope();
				$scopeKey = $scope->getCacheKey();

				if (!isset($cache[$this->mailbox['ID']][$scopeKey][$dirPath][$uidtoken]))
				{
					$cache[$this->mailbox['ID']][$scopeKey][$dirPath][$uidtoken] = [
						'firstLocalUID' => \Bitrix\Mail\MailMessageUidTable::getFirstLocalUID((int) $this->mailbox['ID'], $dirPath, $uidtoken, $scope),
						'lastLocalUID' => \Bitrix\Mail\MailMessageUidTable::getLastLocalUID((int) $this->mailbox['ID'], $dirPath, $uidtoken, $scope),
					];
				}

				$firstLocalUID = $cache[$this->mailbox['ID']][$scopeKey][$dirPath][$uidtoken]['firstLocalUID'];
				$lastLocalUID = $cache[$this->mailbox['ID']][$scopeKey][$dirPath][$uidtoken]['lastLocalUID'];

				if (
					$firstLocalUID > 0 &&
					$lastLocalUID > 0 &&
					(int) $item['UID'] > $firstLocalUID &&
					(int) $item['UID'] < $lastLocalUID
				)
				{
					$lostMessageFields = [
						'ID' => $messageUid,
						'DIR_MD5'  => md5($dirPath),
						'DIR_UIDV' => $uidtoken,
						'MSG_UID'  => $item['UID'],
						'MAILBOX_ID' => $this->mailbox['ID'],
					];

					$this->registerMessage($lostMessageFields, messageStatus: \Bitrix\Mail\MailMessageUidTable::LOST);
				}
			}
		}

		/*
			A dropped entry may be a letter overwritten by an unsolicited response: its database row is
			left unmatched in the excerpt while the server does hold the letter. An incomplete list is
			not trusted with deletion, the leftover rows wait for a run with a complete one.
		*/
		$excerptDeletable = !$walkIncomplete;

		$countUpdated = 0;
		$countDeleted = $excerptDeletable ? count($excerpt) : 0;

		foreach ($update as $seen => $items)
		{
			if (!empty($items))
			{
				if (in_array($seen, array('S', 'U')))
				{
					$method = 'S' == $seen ? 'seen' : 'unseen';
					$this->client->$method($items, $dirPath);
				}
				else
				{
					$countUpdated += count($items);

					$totalValues = count($items);
					$offset = 0;
					$batchSize = 100;

					while ($offset < $totalValues)
					{
						$batchValues = array_slice($items, $offset, $batchSize, true);

						$this->updateMessagesRegistry(
							[
								'@ID' => array_keys($batchValues),
							],
							[
								'IS_SEEN' => $seen,
							],
							$items = [], // @TODO: fix lazyload in MessageEventManager::processOnMailMessageModified()
						);

						$offset += $batchSize;
					}
				}
			}
		}

		if (!empty($excerpt) && $excerptDeletable)
		{
			$totalValues = count($excerpt);
			$offset = 0;
			$batchSize = 100;
			$markedRows = 0;

			while ($offset < $totalValues)
			{
				$batchValues = array_slice($excerpt, $offset, $batchSize, true);

				$result = $this->unregisterMessages(
					[
						'@ID' => array_keys($batchValues),
						'=DIR_MD5'  => md5($dirPath),
					],
					[
						'info' => 'deletion of non-existent messages',
					]
				);

				$marked = $result ? $result->getCount() : 0;
				$markedRows += $marked;
				$countDeleted += $marked;
				$offset += $batchSize;
			}

			if ($markedRows > 0)
			{
				$this->dismissHistoryCoverage($dirPath);
			}
		}

		$this->lastSyncResult['updatedMessages'] += $countUpdated;
		$this->lastSyncResult['deletedMessages'] += $countDeleted;

		$changedMessageIds = [];
		foreach (['Y', 'N'] as $seenFlag)
		{
			foreach ($update[$seenFlag] as $updatedItem)
			{
				if (isset($updatedItem['MESSAGE_ID']) && (int) $updatedItem['MESSAGE_ID'] > 0)
				{
					$changedMessageIds[] = (int) $updatedItem['MESSAGE_ID'];
				}
			}
		}

		if (!empty($changedMessageIds))
		{
			(new \Bitrix\Mail\Internal\Service\Label\LabelCountersService())
				->recalculateForMessages((int) $this->mailbox['ID'], $changedMessageIds);
		}
	}

	protected function completeMessageSync($uid)
	{
		$result = Mail\MailMessageUidTable::update(
			[
				'ID' => $uid,
				'MAILBOX_ID' => $this->mailbox['ID'],
			],
			[
				'IS_OLD' => 'N',
			]
		);

		return $result->isSuccess();
	}

	/**
	 * @param bool $isStorageFailure - set when the message is given up before the server is asked for its
	 *        body, on what registerMessage() reports as a failure: a rejected field check of its own row,
	 *        or an update of an already known row that did not go through. The insert itself is not
	 *        checked there, so a row lost on writing is not one of these cases. Such a failure tells
	 *        nothing about the server and about this message, so the caller does not spend an attempt of
	 *        it - until the excuses of the dir run out.
	 */
	protected function syncMessage(
		$dirPath,
		array $message,
		&$hashesMap = [],
		$ignoreSyncFrom = false,
		$isOutgoing = false,
		$isRecovered = false,
		bool $storeRetryState = false,
		bool $reuseCachedMessage = false,
		bool &$isStorageFailure = false,
	)
	{
		$isStorageFailure = false;
		$fields = $message['__fields'];

		if ($fields['MESSAGE_ID'] > 0)
		{
			$hashesMap[$fields['HEADER_MD5']] = $fields['MESSAGE_ID'];
		}
		else
		{
			if (array_key_exists($fields['HEADER_MD5'], $hashesMap) && $hashesMap[$fields['HEADER_MD5']] > 0)
			{
				$fields['MESSAGE_ID'] = $hashesMap[$fields['HEADER_MD5']];
			}
		}

		$deliveryContext = $this->deliveryContextOverride ?? DeliveryContext::unknown();
		$replaces = $message['__replaces'] ?? null;
		if (!is_null($replaces))
		{
			$deliveryContext = DeliveryContext::repair();
		}
		elseif ($this->getGenerationContext()->isMigrationImport())
		{
			$deliveryContext = DeliveryContext::migration();
		}
		$deliveryContext ??= DeliveryContext::unknown();

		$this->carryOverGenerationPair($message, $fields);

		if ($isOutgoing || !is_null($replaces))
		{
			if (!$this->registerMessage($fields, $replaces, $isOutgoing, redefineInsertDate: false))
			{
				$isStorageFailure = true;

				return false;
			}
		}
		else
		{
			/*
			 * If the message is outgoing but there is no local ID in the header,
			 * we will prepare additional parameters to search for a local copy.
			 */

			$isOutgoing = ($this->getDirsHelper()->getOutcomePath() === $dirPath);

			$idFromHeaderMessage = '';

			if ($isOutgoing && ($message['__header'] instanceof \CMailHeader))
			{
				$idFromHeaderMessage = trim($message['__header']->GetHeader("MESSAGE-ID"), " <>");
			}

			if (!$this->registerMessage(
				$fields,
				isOutgoing: $isOutgoing,
				idFromHeaderMessage: $idFromHeaderMessage,
				redefineInsertDate: false,
			))
			{
				$isStorageFailure = true;

				return false;
			}
		}

		/*
			The row of the new generation exists from here on, whichever way the rest of the sync goes: the
			replacement of its pair happens in the same pass, so the dir never shows the letter twice.
		*/
		$this->takeOverGenerationPair($message, $fields);

		$minimumSyncDate = $this->getMinimumSyncDate();

		if($minimumSyncDate !== false && !$ignoreSyncFrom && $message['__internaldate']->getTimestamp() < $minimumSyncDate)
		{
			return $this->completeMessageSync($fields['ID']) ? null : false;
		}

		if (!empty($message['__created']) && !empty($this->mailbox['OPTIONS']['resync_from']))
		{
			if ($message['__created']->getTimestamp() < $this->mailbox['OPTIONS']['resync_from'])
			{
				return $this->completeMessageSync($fields['ID']) ? null : false;
			}
		}

		if ($fields['MESSAGE_ID'] > 0)
		{
			return $this->completeMessageSync($fields['ID']);
		}

		$messageId = 0;
		$isMigrationImport = $this->getGenerationContext()->isMigrationImport();

		/*
			An imported letter always carries the retry marker: an attempt interrupted
			between the save and the linking must find the created message instead of
			saving it a second time.
		*/
		$retryExternalId = $storeRetryState || $isMigrationImport
			? $this->buildRetryExternalId($fields['ID'])
			: null
		;

		if ($reuseCachedMessage && !$isMigrationImport && $retryExternalId !== null)
		{
			$messageId = $this->findRetryMessageId($retryExternalId);
		}

		if ($messageId === 0 && !empty($message['BODYSTRUCTURE']) && !empty($message['BODY[HEADER]']))
		{
			$message['__bodystructure'] = new Mail\Imap\BodyStructure($message['BODYSTRUCTURE']);

			$message['__parts'] = $this->downloadMessageParts(
				$message['__fields'],
				$message['__bodystructure'],
				$this->isSupportLazyAttachments() ? self::MESSAGE_PARTS_TEXT : self::MESSAGE_PARTS_ALL
			);

			// #119474
			if (!$message['__bodystructure']->isMultipart())
			{
				if (is_array($message['__parts']) && !empty($message['__parts']['BODY[1]']))
				{
					$message['__parts']['BODY[1.MIME]'] = $message['BODY[HEADER]'];
				}
			}
		}
		elseif ($messageId === 0)
		{
			// fallback
			$message['__parts'] = $this->downloadMessage($message['__fields']) ?: false;
		}

		$decision = null;

		/*
			An imported generation decides the local identity of the letter before
			it creates anything: a message already known to the mailbox is linked
			to its existing MESSAGE_ID instead of being saved a second time.
		*/
		if ($messageId === 0 && false !== $message['__parts'] && $isMigrationImport)
		{
			$decidedBefore = false;
			$decision = $this->matchMigratedMessage($message, $fields, $decidedBefore);

			if ($decision === null)
			{
				return false;
			}

			/*
				A letter nobody decided about before this pass cannot have a message of a retry:
				the marker is written by the save, and the save comes after the decision. Asking
				for it anyway costs the whole history of the mailbox once per letter - the marker
				is a column no index of the messages leads with - and a transfer is all letters.
			*/
			$messageId = $decision->isMatched()
				? $decision->messageId
				: ($decidedBefore ? $this->findRetryMessageId($retryExternalId) : 0)
			;
		}

		if ($messageId === 0 && false !== $message['__parts'])
		{
			$dir = $this->getDirsHelper()->getDirByPath($dirPath);

			$messageId = $this->cacheMessage(
				$message,
				[
					'timestamp' => $message['__internaldate']->getTimestamp(),
					'size' => $message['RFC822.SIZE'],
					'outcome' => in_array($this->mailbox['EMAIL'], $message['__from']),
					'draft' => $dir != null && $dir->isDraft() || (isset($message['FLAGS']) && preg_grep('/^ \x5c Draft $/ix', $message['FLAGS'])),
					'trash' => $dir != null && $dir->isTrash(),
					'spam' => $dir != null && $dir->isSpam(),
					'seen' => $fields['IS_SEEN'] == 'Y',
					'recovered' => $isRecovered,
					'fresh_arrival' => $this->isFreshArrival($message),
					'hash' => $fields['HEADER_MD5'],
					'lazy_attachments' => $this->isSupportLazyAttachments(),
					'excerpt' => $fields,
					'external_id' => $retryExternalId,
					'migration_import' => $isMigrationImport,
					'delivery_origin' => $deliveryContext->getOrigin(),
					'suppress_builtin_notifications' => !$deliveryContext->shouldNotify(),
					MailMessageTable::FIELD_SANITIZE_ON_VIEW => $this->isSupportSanitizeOnView(),
				],
			);
		}

		if ($messageId <= 0)
		{
			// the body is not received: the row stays DOWNLOADED and the message is retried later
			return false;
		}

		$hashesMap[$fields['HEADER_MD5']] = $messageId;

		if ($decision !== null)
		{
			if (!$this->storeMigrationMatch($fields['ID'], $messageId, $message, $decision))
			{
				return false;
			}

			/*
				A matched letter is not saved again, so nothing else reads the headers the new source
				reports for it - and the parent they name may have arrived only now, with the history
				this migration brings. Without this the chain of that letter stays cut off at the top
				forever: the answer is in the mailbox, its parent is in the mailbox, and nothing links
				them.
			*/
			if ($decision->isMatched() && ($message['__header'] instanceof \CMailHeader))
			{
				\CMailMessage::completeClosureChain(
					(int)$messageId,
					(int)$this->mailbox['ID'],
					(string)$message['__header']->getHeader('IN-REPLY-TO'),
				);
			}
		}
		elseif (!$this->linkMessage($fields['ID'], $messageId))
		{
			return false;
		}

		if (Mail\Helper\Label\LabelsFeature::isEnabled())
		{
			$this->linkedMessageIds[] = (int) $messageId;
		}

		return $this->completeMessageSync($fields['ID']);
	}

	protected function recalculateLabelCountersForLinkedMessages(): void
	{
		if (empty($this->linkedMessageIds))
		{
			return;
		}

		$messageIds = $this->linkedMessageIds;
		$this->linkedMessageIds = [];

		(new \Bitrix\Mail\Internal\Service\Label\LabelCountersService())
			->recalculateForMessages((int) $this->mailbox['ID'], $messageIds);
	}

	protected function buildRetryExternalId(string $uidId): string
	{
		return sprintf('imap-history-retry:%u:%s', $this->mailbox['ID'], $uidId);
	}

	protected function findRetryMessageId(string $externalId): int
	{
		$row = MailMessageTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailbox['ID'],
				'=EXTERNAL_ID' => $externalId,
			],
			'order' => ['ID' => 'ASC'],
		]);

		return (int)($row['ID'] ?? 0);
	}

	/**
	 * The uids a folder of this source holds after $afterUid, oldest first.
	 *
	 * It goes over the connection this engine already holds: a client of its own per folder
	 * is a TCP handshake, a TLS handshake and a login per folder, next to a living
	 * connection to the very same server - and providers limit both the number of
	 * simultaneous connections and the rate of logins.
	 *
	 * @param int $afterUid The uid a walk of the folder has finished with, 0 to start it.
	 *        The bound goes into the search command, so a walk in several passes costs each
	 *        of them the part that is left instead of the whole folder every time - and the
	 *        numbers of a folder of hundreds of thousands of letters are not built, parsed
	 *        and held in memory over and over again.
	 * @param int|null $uidValidity Receives the epoch of the folder the numbers belong to. A caller that
	 *        keeps a cursor of its own needs it: the numbers of a new epoch start over, so a cursor of
	 *        the previous one would silently filter them all out.
	 * @param int|null $reach How many uids the caller can do something with at all, null for
	 *        the tail of the folder as a whole. Stated here, it bounds the search command and
	 *        not only its answer: a caller that reaches a few thousand letters per pass never
	 *        makes the server search a folder of hundreds of thousands, never reads that answer
	 *        off the socket and never holds it - which is what a walk in passes costs when the
	 *        bound is applied after the numbers have arrived.
	 * @param bool|null $hasMore Receives whether the folder holds letters beyond the ones
	 *        returned. A caller that marks a folder finished needs it: a listing shorter than
	 *        the reach can mean either the end of the folder or the end of the window.
	 * @return int[]|false False when the source cannot list the folder.
	 */
	public function listFolderUids(
		string $dirPath,
		int $afterUid = 0,
		?int &$uidValidity = null,
		?int $reach = null,
		?bool &$hasMore = null,
	)
	{
		$hasMore = false;

		$error = [];
		$meta = $this->client->select($dirPath, $error);
		// The select of the listing below is the same one, so this costs no round trip of its own
		$uidValidity = $meta === false ? null : ((int)($meta['uidvalidity'] ?? 0) ?: null);

		$uids = $reach === null
			? null
			: $this->searchUidWindows($dirPath, $afterUid, $reach, is_array($meta) ? $meta : [], $hasMore)
		;

		if (is_array($uids))
		{
			return $uids;
		}

		/*
			Either the caller asked for the tail as a whole, or the source refuses a range of
			numbers: such a server rejects the whole command instead of ignoring the range, so
			the folder is listed as it was before there was a bound at all. A source that is
			simply gone refuses the second attempt as well.

			No lower bound by date here: the period of the mailbox is applied when a letter is
			received.
		*/
		$whole = $uids === false;
		$listed = $afterUid > 0 && !$whole
			? $this->client->getUidsSince($dirPath, 0, minimumUid: $afterUid + 1)
			: $this->client->getUidsSince($dirPath, 0)
		;

		if ($listed === false && $afterUid > 0 && !$whole)
		{
			$listed = $this->client->getUidsSince($dirPath, 0);
		}

		if ($listed === false)
		{
			$this->warnings->add($this->client->getErrors()->toArray());

			return false;
		}

		$listed = array_map('intval', $listed);
		sort($listed);

		return $this->takeUidTail($listed, $afterUid, $reach, $hasMore);
	}

	/**
	 * The first $reach uids of the folder above $afterUid, asked for as windows of numbers the
	 * server searches one after another.
	 *
	 * The numbers of a folder do not run without gaps - every letter ever deleted from it left
	 * one - so a window of the width of the reach can answer with less than the reach, or with
	 * nothing at all. The width is therefore read off the density the folder reports itself
	 * (its letters over its numbers) and doubled while the walk falls short, up to a cap: what
	 * the cap trades is the peak of one answer against the number of commands, and a region of
	 * numbers a folder has nothing left in is crossed within this one call instead of costing a
	 * pass of its own.
	 *
	 * @param array $meta The answer of the selection of the folder: its letters and the number
	 *        it would give the next one, both free of a round trip of their own.
	 * @return int[]|false|null False when the source refuses a range of numbers, null when the
	 *         folder is not worth windowing at all: one that holds no more letters than the
	 *         reach answers with the reach at most, and one that names no UIDNEXT gives the
	 *         walk no end to stop at.
	 */
	private function searchUidWindows(
		string $dirPath,
		int $afterUid,
		int $reach,
		array $meta,
		?bool &$hasMore,
	)
	{
		$letters = (int)($meta['exists'] ?? 0);
		$highest = (int)($meta['uidnext'] ?? 0) - 1;
		$from = $afterUid + 1;

		if ($reach < 1 || $letters <= $reach || $highest < $from)
		{
			return null;
		}

		$cap = $reach * self::UID_WINDOW_SPAN_CAP;
		$span = min($cap, max($reach, (int)ceil($reach * ($highest - $from + 1) / $letters)));

		$uids = [];
		$exhausted = false;

		while ($from <= $highest)
		{
			$to = min($from + $span - 1, $highest);
			$found = $this->client->getUidsSince($dirPath, 0, maximumUid: $to, minimumUid: $from);

			if ($found === false)
			{
				return false;
			}

			foreach ($found as $uid)
			{
				$uids[] = (int)$uid;
			}
			unset($found);

			$exhausted = $to >= $highest;

			if (count($uids) >= $reach)
			{
				break;
			}

			$from = $to + 1;
			$span = min($cap, $span * 2);
		}

		sort($uids);

		return $this->takeUidTail($uids, $afterUid, $reach, $hasMore, $exhausted);
	}

	/**
	 * What of a listing the caller can do something with: the numbers above its cursor, the
	 * reach of the pass at most.
	 *
	 * @param int[] $uids Ascending.
	 * @param bool $exhausted Whether the listing came from the end of the folder. A listing
	 *        of a window did not, unless the window reached the last number of the folder.
	 * @return int[]
	 */
	private function takeUidTail(
		array $uids,
		int $afterUid,
		?int $reach,
		?bool &$hasMore,
		bool $exhausted = true,
	): array
	{
		if ($afterUid > 0)
		{
			$uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > $afterUid));
		}

		if ($reach !== null && count($uids) > $reach)
		{
			$uids = array_slice($uids, 0, $reach);
			$hasMore = true;

			return $uids;
		}

		$hasMore = !$exhausted;

		return $uids;
	}

	/**
	 * The canonical view of the letters a folder of this source holds, written down
	 * nowhere: no uid row, no message, no matching result.
	 *
	 * The shadow pass of a migration needs the very content the import would compare,
	 * so the fetch, the parsing and the canonicalization are the ones of the import.
	 *
	 * What comes back is the letters the source let itself be read: a chunk that vanished, a
	 * letter without a body structure and an entry no FETCH response of a letter stands behind
	 * are all left out of it. The rehearsal reads one letter less and writes nothing anywhere,
	 * so the count is what suffers, not a decision.
	 *
	 * @param bool $withBody False fetches the envelope and the structure of a letter and
	 *        leaves its parts on the server. Everything the indexed lookups of the matching
	 *        need is in the header block and the body structure - the number of the
	 *        attachments included - so the letters that never reach the comparison of the
	 *        bodies cost no download at all. The view of such a letter reports no body,
	 *        which is exactly what it has.
	 * @param int[] $uids
	 * @return array<int, array{canonical: CanonicalMessageData, reference: string}>|false
	 *         False when the source stops answering.
	 */
	public function listCanonicalMessages(string $dirPath, array $uids, bool $withBody = true)
	{
		$meta = $this->client->select($dirPath, $error);

		if ($meta === false)
		{
			$this->warnings->add($this->client->getErrors()->toArray());

			return false;
		}

		$partFlags = $this->isSupportLazyAttachments() ? self::MESSAGE_PARTS_TEXT : self::MESSAGE_PARTS_ALL;
		$canonical = [];

		foreach (array_chunk($uids, 10) as $chunk)
		{
			$messages = $this->client->fetch(
				true,
				$dirPath,
				join(',', $chunk),
				'(UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER])',
				$error,
				'list'
			);

			if ($messages === false)
			{
				$this->warnings->add($this->client->getErrors()->toArray());

				return false;
			}

			if (empty($messages))
			{
				// The chunk has vanished from the server: the rest of the list is still expected
				continue;
			}

			/*
				An unsolicited response about a letter outside the chunk brings a sequence number of its
				own, so no solicited entry merges with it and the entry arrives without a uid. There is no
				letter behind it to canonicalize: dropped before the parsing, the way the sync paths drop
				it, it reaches neither the header block nor the uid formula. The flags of a letter take no
				part in the canonical view, so an entry that came short of them is read like any other.
			*/
			$messages = array_filter($messages, static fn ($item) => isset($item['UID']));

			$this->parseHeaders($messages);

			foreach ($messages as &$message)
			{
				$this->fillMessageFields($message, $dirPath, $meta['uidvalidity']);

				if (empty($message['BODYSTRUCTURE']) || empty($message['BODY[HEADER]']))
				{
					continue;
				}

				$message['__bodystructure'] = new Mail\Imap\BodyStructure($message['BODYSTRUCTURE']);
				$message['__parts'] = $withBody
					? $this->downloadMessageParts(
						$message['__fields'],
						$message['__bodystructure'],
						$partFlags
					)
					: []
				;

				// #119474, exactly as the import reads a message of a single part
				if ($withBody && !$message['__bodystructure']->isMultipart())
				{
					if (is_array($message['__parts']) && !empty($message['__parts']['BODY[1]']))
					{
						$message['__parts']['BODY[1.MIME]'] = $message['BODY[HEADER]'];
					}
				}

				$view = $this->buildCanonicalMessage($message);

				if ($view === null)
				{
					continue;
				}

				$canonical[(int)$message['UID']] = [
					'canonical' => $view,
					'reference' => $this->getMigratorReference($message),
				];
			}

			unset($message);
		}

		return $canonical;
	}

	/**
	 * The decision of the matcher for a message fetched by an imported generation
	 * (ALG-01): the uid is already registered with MESSAGE_ID = 0, the MIME is
	 * fetched and parsed, and only now the local identity of the letter is chosen.
	 *
	 * @param bool|null $decidedBefore Receives whether the journal of the matching already held
	 *        a result of this uid when the pass reached it - an attempt that ran before this one
	 *        and did not finish. A letter recognized as one this stage appended itself answers
	 *        false: nothing of it was ever decided.
	 * @return MatchDecision|null Null when the import of this message must not proceed yet.
	 */
	protected function matchMigratedMessage(
		array &$message,
		array $fields,
		?bool &$decidedBefore = null,
	): ?MatchDecision
	{
		$decidedBefore = false;

		$context = $this->getGenerationContext();
		$matcher = $this->getMessageMatcher();

		$appended = $this->recognizeAppendedByTailMark($message, $fields);

		if ($appended !== null)
		{
			return $appended;
		}

		if (!$matcher->ensureCandidateFingerprints($context))
		{
			$this->errors->setError(new Main\Error(
				sprintf('The candidate fingerprints of the mailbox %u are not built yet', $context->mailboxId),
				'MAIL_SOURCE_GENERATION_FINGERPRINTS_INCOMPLETE'
			));

			return null;
		}

		$canonical = $this->buildCanonicalMessage($message);

		if ($canonical === null)
		{
			return null;
		}

		$message['__canonical'] = $canonical;

		return $matcher->match(
			$context,
			$fields['ID'],
			$canonical,
			$this->getMigratorReference($message),
			$decidedBefore,
		);
	}

	/**
	 * Closes the import of one uid (DATA-01): the physical row takes the local
	 * identity of the decision, the result becomes terminal and the representation
	 * of this generation joins the fingerprints of that logical message, so the next
	 * generation finds it by any of the accumulated representations.
	 *
	 * A matched letter is not saved again, so only the data it never received is
	 * completed afterwards - outside the transaction and without touching anything
	 * that is already there.
	 */
	protected function storeMigrationMatch(string $uidId, int $messageId, array $message, MatchDecision $decision): bool
	{
		$context = $this->getGenerationContext();
		$canonical = ($message['__canonical'] ?? null) instanceof CanonicalMessageData
			? $message['__canonical']
			: null
		;

		$committed = $this->getMigrationImporter()->commit(
			$context,
			$uidId,
			$messageId,
			$decision,
			$canonical,
			$this->getMigratorReference($message),
		);

		if (!$committed)
		{
			$this->errors->setError(new Main\Error(
				sprintf('The import of the uid %s could not be committed', $uidId),
				'MAIL_SOURCE_GENERATION_IMPORT_FAILED'
			));

			return false;
		}

		if ($decision->isMatched())
		{
			$this->completeMatchedMessage($messageId, $message);
		}

		return true;
	}

	/**
	 * Finishes the download the existing message never got: the body it is still
	 * waiting for and the attachments a lazy save has not created. Attachments are
	 * only offered when this run really downloaded them.
	 */
	protected function completeMatchedMessage(int $messageId, array &$message): void
	{
		$content = $this->buildMessageContent($message);

		if ($content === null)
		{
			return;
		}

		[$bodyHtml, $bodyText, $attachments] = $content;

		$this->getMigrationImporter()->completeMatchedMessage(
			$this->getGenerationContext(),
			$messageId,
			(string)$bodyHtml,
			(string)$bodyText,
			$this->isSupportLazyAttachments() ? [] : $attachments,
		);
	}

	/**
	 * The canonical view of a fetched message, built from the very content the save
	 * path would receive. The message is not saved to be compared.
	 */
	/**
	 * Parts of the letter being walked that the other classification rule of the module would put
	 * on the other side. The walk of the structure asks whether a part declared itself an
	 * attachment; the parser of a whole MIME asks whether it carries a file name. A text part with
	 * a name but no declaration is a body here and an attachment there, and a nameless part
	 * declared an attachment is the other way round.
	 *
	 * @var array{attachment: int, body: int}
	 */
	private array $partsClassifiedByTheOtherRule = ['attachment' => 0, 'body' => 0];

	private function countPartClassifiedByTheOtherRule(Mail\Imap\BodyStructure $item): void
	{
		if (!$item->isText() || $item->getSubtype() === 'calendar')
		{
			// Not a text part: both rules call it an attachment
			return;
		}

		$named = trim((string)($item->getParams()['name'] ?? '')) !== '';

		if ($item->isAttachment() && !$named)
		{
			$this->partsClassifiedByTheOtherRule['body']++;
		}
		elseif (!$item->isAttachment() && $named)
		{
			$this->partsClassifiedByTheOtherRule['attachment']++;
		}
	}

	/**
	 * The number of attachments the same letter would carry under the other rule, or null when the
	 * two rules agree on every part of it.
	 */
	private function attachmentCountOfTheOtherRule(int $attachmentCount): ?int
	{
		$difference = $this->partsClassifiedByTheOtherRule['attachment']
			- $this->partsClassifiedByTheOtherRule['body']
		;

		if ($difference === 0)
		{
			return null;
		}

		return max(0, $attachmentCount + $difference);
	}

	protected function buildCanonicalMessage(array &$message): ?CanonicalMessageData
	{
		$content = $this->buildMessageContent($message);

		if ($content === null)
		{
			return null;
		}

		[$bodyHtml, $bodyText, $attachments] = $content;

		$header = $message['__header'];

		/*
			Only a part this run really downloaded names its attachment: its name went through
			the header decoders, so it is the name a human reads and the name the save path
			stores. A part known from the structure alone carries the bytes of the wire - an
			encoded word, a continuation, or the literal the structure invents for a nameless
			part - and comparing that with a stored name says nothing about the letters.
			The count still covers every attachment, so an incomplete list of names turns the
			composition check into the comparison of numbers it always was.
		*/
		$downloaded = array_filter(
			$attachments,
			static fn(array $attachment) => empty($attachment['__structureOnly'])
		);

		return $this->getMessageMatcher()->getNormalizer()->fromIncomingMime(
			[
				'MESSAGE-ID' => $header->getHeader('MESSAGE-ID'),
				'SUBJECT' => $header->getHeader('SUBJECT'),
				'FROM' => $header->getHeader('FROM'),
				'TO' => $header->getHeader('TO'),
				'CC' => $header->getHeader('CC'),
				'DATE' => $header->getHeader('DATE'),
			],
			(string)$bodyHtml,
			(string)$bodyText,
			array_map(
				static fn(array $attachment) => (string)($attachment['FILENAME'] ?? ''),
				array_values($downloaded)
			),
			count($attachments),
			array_filter([$this->attachmentCountOfTheOtherRule(count($attachments))], is_int(...)),
		);
	}

	/**
	 * The letter the tail append stage put onto this source itself, on a source that named no
	 * coordinates for it: the fallback route of the recognition
	 * {@see \Bitrix\Mail\Internal\Service\SourceGeneration\TailMarkService}.
	 *
	 * The identity comes from the record the stage wrote BEFORE the append and never from the
	 * header - a mark without a record of its own is answered with nothing, and this letter
	 * then goes the ordinary route of the matching like any other. So do a mark past its
	 * lifetime and a mark a letter of the mailbox has been recognized by already.
	 *
	 * The verdict is stored the same way the main route stores it, so a repeated read of the
	 * same placement stops at the stored result instead of asking about the mark twice - and
	 * the mark, being a one time one, would answer nothing the second time.
	 *
	 * @return MatchDecision|null Null when nothing of the letter says it came from the stage.
	 */
	protected function recognizeAppendedByTailMark(array &$message, array $fields): ?MatchDecision
	{
		$mark = $this->getTailMark($message);

		if ($mark === '')
		{
			return null;
		}

		$context = $this->getGenerationContext();
		$messageId = $this->getTailMarkService()->recognize($context, $mark, $fields);

		if ($messageId <= 0)
		{
			return null;
		}

		$uidId = (string)$fields['ID'];

		if (!$this->getMessageMatcher()->recordAppendedMessage(
			$context,
			$uidId,
			$messageId,
			MatchDecision::REASON_TAIL_MARK,
		))
		{
			return null;
		}

		/*
			The canonical view is built for the sake of the representation the commit registers
			afterwards, and never for a comparison: there is nothing to compare here. A letter it
			cannot be built from is still this letter, so the recognition stands either way.
		*/
		$canonical = $this->buildCanonicalMessage($message);

		if ($canonical !== null)
		{
			$message['__canonical'] = $canonical;
		}

		return new MatchDecision(
			SourceGenerationMatchTable::STATE_MATCHED,
			$messageId,
			MatchDecision::REASON_TAIL_MARK,
		);
	}

	/**
	 * The one time mark of the tail append stage, as the MIME carries it.
	 *
	 * Read only by an import into a generation being prepared
	 * ({@see Context::acceptsTailMark()}). An ordinary synchronization of the mailbox never
	 * looks at the header, whatever a letter of it carries - and it is a letter of the mailbox
	 * of the client, so anybody with access to that mailbox can write one.
	 */
	protected function getTailMark(array $message): string
	{
		if (!$this->getGenerationContext()->acceptsTailMark())
		{
			return '';
		}

		if (preg_match(
			'/^' . preg_quote(TailMarkService::HEADER, '/') . ':\s*([0-9a-f]+)/im',
			(string)($message['BODY[HEADER]'] ?? ''),
			$matches
		))
		{
			return $matches[1];
		}

		return '';
	}

	protected function getTailMarkService(): TailMarkService
	{
		return $this->tailMarkService ??= new TailMarkService();
	}

	/**
	 * The reference a managed migrator puts into every copy it makes: the coordinate of the
	 * letter on the source it was taken from
	 * {@see \Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MigratorReference}.
	 *
	 * The header is read only for an operation that declared such a service. Nothing
	 * authenticates a MIME header, so a letter delivered to the new source the ordinary way
	 * would otherwise name any message of the mailbox as its own.
	 *
	 * More than one such header and the letter counts as unmarked: the contract asks for
	 * exactly one, and choosing among several would let a letter carrying the coordinate of
	 * somebody else beside its own decide which of the two the portal follows.
	 */
	protected function getMigratorReference(array $message): string
	{
		if (!$this->getGenerationContext()->acceptsMigratorReference())
		{
			return '';
		}

		$found = preg_match_all(
			'/^' . preg_quote(self::MIGRATOR_REFERENCE_HEADER, '/') . ':\s*(\S+)/im',
			(string)($message['BODY[HEADER]'] ?? ''),
			$matches
		);

		return $found === 1 ? $matches[1][0] : '';
	}

	public function downloadAttachments(array &$excerpt)
	{
		if (empty($excerpt['MSG_UID']) || empty($excerpt['DIR_MD5']) || !$this->servesPlacement($excerpt))
		{
			return false;
		}

		$dirPath = $this->getDirsHelper()->getDirPathByHash($excerpt['DIR_MD5']);
		if (empty($dirPath))
		{
			return false;
		}

		if (!$this->servesFolderVersion($excerpt, $dirPath))
		{
			return false;
		}

		$message = $this->client->fetch(true, $dirPath, $excerpt['MSG_UID'], '(BODYSTRUCTURE)', $error);
		if (empty($message['BODYSTRUCTURE']))
		{
			// @TODO: fallback

			if (false === $message)
			{
				$this->errors = new Main\ErrorCollection($this->client->getErrors()->toArray());
			}

			return false;
		}

		if (!is_array($message['BODYSTRUCTURE']))
		{
			$this->errors = new Main\ErrorCollection(array(
				new Main\Error('Helper\Mailbox\Imap: Invalid BODYSTRUCTURE', 0),
				new Main\Error((string)$message['BODYSTRUCTURE'], -1),
			));
			return false;
		}

		$message['__bodystructure'] = new Mail\Imap\BodyStructure($message['BODYSTRUCTURE']);

		$parts = $this->downloadMessageParts(
			$excerpt,
			$message['__bodystructure'],
			self::MESSAGE_PARTS_ATTACHMENT
		);

		$attachments = array();

		$message['__bodystructure']->traverse(
			function (Mail\Imap\BodyStructure $item) use (&$parts, &$attachments)
			{
				if ($item->isMultipart() || $item->isBodyText())
				{
					return;
				}

				$attachments[] = \CMailMessage::decodeMessageBody(
					\CMailMessage::parseHeader(
						$parts[sprintf('BODY[%s.MIME]', $item->getNumber())],
						$this->mailbox['LANG_CHARSET']
					),
					$parts[sprintf('BODY[%s]', $item->getNumber())],
					$this->mailbox['LANG_CHARSET']
				);
			}
		);

		return $attachments;
	}

	protected function cacheMessage(&$message, $params = array())
	{
		if (!is_array($message))
		{
			return parent::cacheMessage($message, $params);
		}

		if (!is_array($message['__parts']))
		{
			return parent::cacheMessage($message['__parts'], $params);
		}

		$content = $this->buildMessageContent($message);

		if ($content === null)
		{
			return false;
		}

		[$bodyHtml, $bodyText, $attachments] = $content;

		$params['log_parts'] = $message['__parts'];

		return \CMailMessage::saveMessage(
			$this->mailbox['ID'],
			$dummyBody,
			$message['__header'],
			$bodyHtml,
			$bodyText,
			$attachments,
			$params
		);
	}

	/**
	 * The html, the text and the attachments of a fetched message, exactly as the
	 * save path receives them. The matching of an imported generation needs the
	 * same content before it decides whether to create a local message at all.
	 *
	 * @return array|null [html, text, attachments] or null when the message cannot be read
	 */
	protected function buildMessageContent(array &$message): ?array
	{
		// An imported message is read twice: once to canonicalize it, once to save it
		if (array_key_exists('__content', $message))
		{
			return $message['__content'];
		}

		if (empty($message['__header']))
		{
			return null;
		}

		if (empty($message['__bodystructure']) || !($message['__bodystructure'] instanceof Mail\Imap\BodyStructure))
		{
			return $message['__content'] = $this->buildContentOfWholeMessage($message);
		}

		$this->partsClassifiedByTheOtherRule = ['attachment' => 0, 'body' => 0];

		$complete = function (&$html, &$text)
		{
			if ('' !== $html && '' === $text)
			{
				$text = html_entity_decode(
					htmlToTxt($html),
					ENT_QUOTES | ENT_HTML401,
					$this->mailbox['LANG_CHARSET']
				);
			}
			elseif ('' === $html && '' !== $text)
			{
				$html = txtToHtml($text, false, 120);
			}
		};

		[$bodyHtml, $bodyText, $attachments] = $message['__bodystructure']->traverse(
			function (Mail\Imap\BodyStructure $item, &$subparts) use (&$message, &$complete)
			{
				$parts = &$message['__parts'];

				$html = '';
				$text = '';
				$attachments = array();

				if ($item->isMultipart())
				{
					if ('alternative' === $item->getSubtype())
					{
						foreach ($subparts as $part)
						{
							$part = $part[0];

							if ('' !== $part[0])
							{
								$html = $part[0];
							}

							if ('' !== $part[1])
							{
								$text = $part[1];
							}

							if (!empty($part[2]))
							{
								$attachments = array_merge($attachments, $part[2]);
							}
						}

						$complete($html, $text);
					}
					else
					{
						foreach ($subparts as $part)
						{
							$part = $part[0];

							$complete($part[0], $part[1]);

							if ('' !== $part[0] || '' !== $part[1])
							{
								$html .= $part[0] . "\r\n\r\n";
								$text .= $part[1] . "\r\n\r\n";
							}

							$attachments = array_merge($attachments, $part[2]);
						}
					}

					$html = trim($html);
					$text = trim($text);
				}
				else
				{
					if (array_key_exists(sprintf('BODY[%s]', $item->getNumber()), $parts))
					{
						$part = \CMailMessage::decodeMessageBody(
							\CMailMessage::parseHeader(
								$parts[sprintf('BODY[%s.MIME]', $item->getNumber())],
								$this->mailbox['LANG_CHARSET']
							),
							$parts[sprintf('BODY[%s]', $item->getNumber())],
							$this->mailbox['LANG_CHARSET']
						);
					}
					else
					{
						$part = [
							'CONTENT-TYPE' => $item->getType() . '/' . $item->getSubtype(),
							'CONTENT-ID'   => $item->getId(),
							'BODY'         => '',
							'FILENAME'     => $item->getParams()['name'],
							// the name is the one the structure spells on the wire: no header decoding took place
							'__structureOnly' => true,
						];
					}

					$this->countPartClassifiedByTheOtherRule($item);

					if (!$item->isBodyText())
					{
						$attachments[] = $part;
					}
					elseif (!empty($part))
					{
						if ('html' === $item->getSubtype())
						{
							$html = $part['BODY'];
						}
						else
						{
							$text = $part['BODY'];
						}
					}
				}

				return array($html, $text, $attachments);
			}
		)[0];

		$complete($bodyHtml, $bodyText);

		$message['__content'] = [$bodyHtml, $bodyText, $attachments];

		return $message['__content'];
	}

	/**
	 * The content of a letter whose source describes no structure for it. The whole message has been
	 * fetched instead, and the ordinary save path derives the html, the text and the attachments from
	 * it by itself - so an imported generation reads it the same way instead of leaving the letter
	 * behind. Without content there is no canonical view, without a canonical view no matching
	 * decision, and such a letter was never imported at all: its row stayed undownloaded and every
	 * following pass retried it.
	 *
	 * @return array|null [html, text, attachments] or null when there is no whole message either
	 */
	private function buildContentOfWholeMessage(array $message): ?array
	{
		$whole = $message['__parts'] ?? null;

		if (!is_string($whole) || $whole === '')
		{
			return null;
		}

		[, $bodyHtml, $bodyText, $attachments] = \CMailMessage::parseMessage(
			$whole,
			$this->mailbox['CHARSET'] ?: $this->mailbox['LANG_CHARSET'],
		);

		return [$bodyHtml, $bodyText, $attachments];
	}

	public function getMinimumSyncDate()
	{
		$minimumDate = false;

		if(!empty($this->mailbox['OPTIONS']['sync_from']))
		{
			$minimumDate = $this->mailbox['OPTIONS']['sync_from'];
		}

		$syncOldLimit = Mail\Helper\LicenseManager::getSyncOldLimit();

		if($syncOldLimit > 0)
		{
			$syncOldLimit = SyncPeriodBoundary::dayStartUtcMinusDays($syncOldLimit);

			/*
				Checking in case of changes in tariff limits
			*/
			if($minimumDate === false || $minimumDate < $syncOldLimit)
			{
				$minimumDate = $syncOldLimit;
			}
		}
		return $minimumDate;
	}

	protected function getSyncRange($dirPath, &$uidtoken, $intervalSynchronizationAttempts = 0)
	{
		$meta = $this->client->select($dirPath, $error);
		if (false === $meta)
		{
			$this->warnings->add($this->client->getErrors()->toArray());

			return null;
		}

		if (!($meta['exists'] > 0))
		{
			return null;
		}

		$uidtoken = $meta['uidvalidity'];

		/*
			The interval may be smaller if the uid of the last message
			in the database is close to the split point in the set of intervals
		*/
		$maximumLengthSynchronizationInterval = $this->getMaximumSynchronizationLengthsOfIntervals($intervalSynchronizationAttempts);

		$rangeGetter = function ($min, $max) use ($dirPath, $uidtoken, &$rangeGetter, $maximumLengthSynchronizationInterval)
		{

			$size = $max - $min + 1;

			$set = [];
			$d = $size < 1000 ? $maximumLengthSynchronizationInterval : pow(10, round(ceil(log10($size) - 0.7) / 2) * 2 - 2);

			//Take intermediate interval values
			for ($i = $min; $i <= $max; $i = $i + $d)
			{
				$set[] = $i;
			}

			/*
				The end of the expected interval should not exceed the identifier of the last message
			*/
			if (count($set) > 1 && end($set) >= $max)
			{
				array_pop($set);
			}

			//The last item in the set must match the last item on the service
			$set[] = $max;

			//Return messages starting from the 1st existing one
			$set = $this->client->fetch(false, $dirPath, join(',', $set), '(UID)', $error);

			if (empty($set))
			{
				return false;
			}

			ksort($set);

			static $uidMinInDatabase, $uidMaxInDatabase, $takeFromDown;

			if (!isset($uidMinInDatabase, $uidMaxInDatabase, $takeFromDown))
			{
				$messagesUidBoundariesIntervalInDatabase = $this->getUidRange($dirPath, $uidtoken);

				if ($messagesUidBoundariesIntervalInDatabase)
				{
					$uidMinInDatabase = $messagesUidBoundariesIntervalInDatabase['MIN'];
					$uidMaxInDatabase = $messagesUidBoundariesIntervalInDatabase['MAX'];
					$takeFromDown = $messagesUidBoundariesIntervalInDatabase['TAKE_FROM_DOWN'];
				}
				else
				{
					$takeFromDown = true;
					$uidMinInDatabase = $uidMaxInDatabase = (end($set)['UID'] + 1);
				}
			}

			if (count($set) == 1)
			{
				$uid = reset($set)['UID'];

				if ($uid > $uidMaxInDatabase || $uid < $uidMinInDatabase)
				{
					return array($uid, $uid);
				}
			}
			elseif (end($set)['UID'] > $uidMaxInDatabase)
			{
				/*
					Select the closest element with the largest uid
					from the set of messages on the service (every hundredth)
					to a message from the database (synchronized) with the maximum uid.
				*/
				do
				{
					$max = current($set)['id'];
					$min = prev($set)['id'];
				}
				while (current($set)['UID'] > $uidMaxInDatabase && prev($set) && next($set));

				if ($max - $min > $maximumLengthSynchronizationInterval)
				{
					return $rangeGetter($min, $max);
				}
				else
				{
					/*
						Since we upload messages "up",
						we know the upper ones and there is no point in "capturing" existing messages in the interval.
						The selection is made within the interval, so the presence of extreme messages with the specified identifiers is not necessary.
						+ 1 / - do not include an already uploaded message
					*/
					return array(
						max($set[$min]['UID'], $uidMaxInDatabase + 1),
						$set[$max]['UID'],
					);
				}
			}
			elseif (reset($set)['UID'] < $uidMinInDatabase && $takeFromDown)
			{
				do
				{
					$min = current($set)['id'];
					$max = next($set)['id'];
				}
				while (current($set)['UID'] < $uidMinInDatabase && next($set) && prev($set));

				if ($max - $min > $maximumLengthSynchronizationInterval)
				{
					return $rangeGetter($min, $max);
				}
				else
				{
					/*
						Since we upload messages "down",
						we know the upper ones and there is no point in "capturing" existing messages in the interval.
						The selection is made within the interval, so the presence of extreme messages with the specified identifiers is not necessary.
						- 1 / - do not include an already uploaded message
					*/
					return array(
						min($set[$max]['UID'], $uidMinInDatabase - 1),
						$set[$min]['UID'],
					);
				}
			}

			return null;
		};

		return $rangeGetter(1, $meta['exists']);
	}

	protected function getUidRange($dirPath, $uidtoken)
	{
		$filter = $this->getGenerationScope()->apply(array(
			'=DIR_MD5'  => md5(Emoji::encode($dirPath)),
			'=DIR_UIDV' => $uidtoken,
			'>MSG_UID'  => 0,
		));

		$minimumSyncDate = $this->getMinimumSyncDate();

		$takeFromDown = $minimumSyncDate === false;

		$min = $this->listMessages(
			array(
				'select' => array(
					'MIN' => 'MSG_UID',
				),
				'filter' => $filter,
				'order'  => array(
					'MSG_UID' => 'ASC',
				),
				'limit'  => 1,
			),
			false
		)->fetch();

		$max = $this->listMessages(
			array(
				'select' => array(
					'MAX' => 'MSG_UID',
				),
				'filter' => $filter,
				'order'  => array(
					'MSG_UID' => 'DESC',
				),
				'limit'  => 1,
			),
			false
		)->fetch();

		if ($min && $max)
		{
			return array(
				'MIN' => $min['MIN'],
				'MAX' => $max['MAX'],
				'TAKE_FROM_DOWN' => $takeFromDown,
			);
		}

		return null;
	}

	/*
		Loads the dir history from the working list returned by the server-side date search
		instead of the legacy UID descent. The UID rows (paired with UIDVALIDITY) are the only
		progress state: every processed UID either gets a row or disappears from the next
		search response, so the remainder strictly shrinks between sessions.
	*/
	protected function syncDirHistoryByPeriod(
		Mail\Internals\Entity\MailboxDirectory $dir,
		?int $maximumUid = null,
		?int $expectedUidValidity = null,
	): void
	{
		$boundary = $this->getMinimumSyncDate();

		if ($boundary === false)
		{
			// unlimited period: the legacy flow covers the whole dir
			return;
		}

		$boundary = (int) $boundary;

		$dirId = (int)$dir->getId();
		$attemptService = $this->getHistorySyncAttemptService();
		/*
			Everything the run knows about the dir beforehand is read in one pass. It is read before the dir is
			reopened: a run refused at the level of the dir is postponed instead of repeated, and reopening the
			dir, searching it over the period and walking the remainder is the work the delay saves - the dir
			itself is already open by then, the live pass having opened it. The coverage marker comes with the
			same read but is checked later, because it depends on the UIDVALIDITY the dir answers with.
		*/
		$dirState = $attemptService->readDirRunState($dirId, self::HISTORY_SYNC_COVERAGE_PROPERTY);
		$retryAfter = $dirState['retryAfter'];

		if (!HistorySyncAttemptService::isRetryDue($retryAfter))
		{
			return;
		}

		$isRetryMarkStored = $retryAfter !== null;

		$error = [];
		$meta = $this->client->select($dir->getPath(), $error);

		if (false === $meta)
		{
			$this->warnings->add($this->client->getErrors()->toArray());
			$attemptService->postponeRetry($dirId, $isRetryMarkStored);

			return;
		}

		$uidValidity = (int) $meta['uidvalidity'];
		if ($expectedUidValidity !== null && $uidValidity !== $expectedUidValidity)
		{
			return;
		}

		if (static::isHistoryCoverageMarkerActual($dirState['coverageMarker'], $uidValidity, $boundary))
		{
			return;
		}

		/*
			Counted once per run and never per message: a failure that is not the message's own costs it no
			attempt, and how many times in a row that may happen to one dir is what the count limits. A
			run that fails before this point is not counted - it never got as far as the server search,
			which is the load the limit exists to stop.
		*/
		$excusedRunCount = $dirState['excusedRunCount'];
		$areExcusesExhausted = HistorySyncAttemptService::areRunExcusesExhausted($excusedRunCount);

		$clientErrorsBefore = count($this->client->getErrors());

		$uids = $maximumUid === 0
			? []
			: $this->client->getUidsSince(
				$dir->getPath(),
				$boundary - self::HISTORY_SYNC_BOUNDARY_MARGIN,
				$maximumUid,
			)
		;

		if (false === $uids)
		{
			$this->warnings->add($this->client->getErrors()->toArray());
			$attemptService->registerExcusedRun($dirId, $excusedRunCount);
			$attemptService->postponeRetry($dirId, $isRetryMarkStored);

			return;
		}

		$attemptCountByUid = [];
		$remaining = $this->collectPendingUids($dir, $uidValidity, $uids, $boundary, $attemptCountByUid);

		if (count($this->client->getErrors()) > $clientErrorsBefore)
		{
			$attemptService->registerExcusedRun($dirId, $excusedRunCount);
			$attemptService->postponeRetry($dirId, $isRetryMarkStored);

			return;
		}

		if ($remaining === [])
		{
			$this->markHistoryPeriodCovered($dir, $uidValidity, $boundary, array_keys($attemptCountByUid));

			return;
		}

		$pass = $this->runWithDeliveryContext(
			DeliveryContext::backfill(),
			fn () => $this->syncMessagesPass(
				$this->mailbox['ID'],
				$dir->getPath(),
				$remaining,
				false,
				false,
				stopOnEmptyChunk: false,
				failOnLocalError: true,
			),
		);

		/*
			Once the excuses of the dir are spent, every message left behind pays for it: a transport
			error is no longer read as somebody else's fault, so the message spends its attempts like any
			other and is given up on at its own limit.
		*/
		$chargedUids = $areExcusesExhausted ? $pass->getDeferredUids() : $pass->getChargedUids();

		/*
			The attempts are counted before the run is judged as a whole on purpose: a link that broke on
			the last message must not undo what the messages the server refused before it have paid.
		*/
		foreach ($chargedUids as $uid)
		{
			$attemptCount = $attemptService->registerFailedAttempt(
				$this->buildMessageIdForDataBase($dir->getPath(), $uidValidity, $uid),
				$attemptCountByUid[(int)$uid] ?? 0,
			);

			if (HistorySyncAttemptService::isQuarantined($attemptCount))
			{
				$this->logHistorySyncQuarantine($dir, $uid, $attemptCount);
			}
		}

		// only the counters that really exist are deleted: the rest of the list has nothing to clear
		$attemptService->clearAttempts($this->buildMessageRowIds(
			$dir->getPath(),
			$uidValidity,
			array_intersect($pass->getCompletedUids(), array_keys($attemptCountByUid)),
		));

		$isRunDropped = (
			!$pass->isSucceeded()
			&& !$pass->hasStoppedOnTimeQuota()
		) || count($this->client->getErrors()) - $pass->getMessageClientErrorCount() > $clientErrorsBefore;

		/*
			A pass given back by the clock has concluded nothing: the remainder of the list was never
			reached, so it appears in none of the per-message verdicts and an empty deferred list must not
			be read as "everything is done". Without this the excuses the dir had saved up would be handed
			back on every staged run.
		*/
		$this->updateExcusedRunCount(
			$dirId,
			$excusedRunCount,
			wasExcuseGranted: $isRunDropped || count($pass->getDeferredUids()) > count($chargedUids),
			leftNothingBehind: !$isRunDropped
				&& !$pass->hasStoppedOnTimeQuota()
				&& !$pass->hasIncompleteList()
				&& $pass->getDeferredUids() === [],
		);

		if ($isRunDropped)
		{
			/*
				The connection broke or a whole batch failed: the run is dropped as it always was, and the
				messages it never reached keep their attempts. Refusals charged to single messages are
				excluded from the check - those are what the attempt limit exists for. Nobody paid for the
				failure, so the dir waits out the delay instead of trying again in ten minutes.
			*/
			$attemptService->postponeRetry($dirId, $isRetryMarkStored);

			return;
		}

		if ($pass->hasIncompleteList())
		{
			/*
				A list that lost letters leaves no per-message verdict behind to read: the letter it lost
				appears neither among the deferred nor among the completed, so a coverage marker written
				here would be the one thing the dir never comes back from. Nobody paid for the loss
				either - it is a refusal of the whole list, charged to no message and excused by no run,
				so the dir waits out the delay of a refusal instead of asking again in ten minutes. What
				the loss is due to may well repeat itself session after session: an unsolicited report
				about a letter of another range, or a parse that broke off before the uid.
			*/
			$attemptService->postponeRetry($dirId, $isRetryMarkStored);

			return;
		}

		if ($isRetryMarkStored)
		{
			// the run went through without a refusal of the whole dir: the delay it waited out is spent
			$attemptService->clearPostponedRetry($dirId);
		}

		if (!$pass->isSucceeded() || $pass->getDeferredUids() !== [])
		{
			// the dir is not covered after a failed pass or while some of its messages have no body yet
			return;
		}

		if ($this->isTimeQuotaExceeded())
		{
			// the rest of the list continues next session
			return;
		}

		$this->markHistoryPeriodCovered($dir, $uidValidity, $boundary, array_keys($attemptCountByUid));
	}

	/**
	 * @param int[] $countedUids - uids of this dir that hold an attempt counter
	 */
	private function markHistoryPeriodCovered(
		Mail\Internals\Entity\MailboxDirectory $dir,
		int $uidValidity,
		int $boundary,
		array $countedUids,
	): void
	{
		$this->writeHistoryCoverageMarker($dir, $uidValidity, $boundary);
		/*
			The dir starts over with a clean limit: an extended period or a new dir generation has to give
			the messages left behind their attempts back instead of inheriting a spent limit. Everything the
			dir carries goes for the same reason - the excused runs and the postponed retry included.
		*/
		$attemptService = $this->getHistorySyncAttemptService();
		$attemptService->clearAttempts(
			$this->buildMessageRowIds($dir->getPath(), $uidValidity, $countedUids)
		);
		$attemptService->clearExcusedRuns((int)$dir->getId());
		$attemptService->clearPostponedRetry((int)$dir->getId());
	}

	/**
	 * Runs that let a message off its attempt are counted per dir, so that the excuses run out. Without
	 * that limit a body whose reading breaks the link every time excuses itself forever: the run is
	 * dropped before any accounting, the message keeps its attempts, the dir never gets its coverage
	 * marker, and the server search over the period repeats every session for good.
	 *
	 * @param int $excusedRunCount - excused runs the dir had before this one
	 * @param bool $wasExcuseGranted - the run was dropped as a whole, or a message it deferred paid nothing
	 * @param bool $leftNothingBehind - the run concluded every message it took, so there is nothing left
	 *        to excuse next time
	 */
	private function updateExcusedRunCount(
		int $dirId,
		int $excusedRunCount,
		bool $wasExcuseGranted,
		bool $leftNothingBehind,
	): void
	{
		$attemptService = $this->getHistorySyncAttemptService();

		if ($wasExcuseGranted)
		{
			$attemptService->registerExcusedRun($dirId, $excusedRunCount);

			return;
		}

		/*
			Only a run that concluded everything clears the count, and a short outage therefore does not
			add up. Clearing it whenever nothing was excused would instead hand the excuses back before
			every single attempt, and the worst case would become the product of the two limits instead of
			their sum.
		*/
		if ($leftNothingBehind && $excusedRunCount > 0)
		{
			$attemptService->clearExcusedRuns($dirId);
		}
	}

	private function getHistorySyncAttemptService(): HistorySyncAttemptService
	{
		return $this->historySyncAttemptService ??= new HistorySyncAttemptService((int)$this->mailbox['ID']);
	}

	/**
	 * @param int[]|string[] $uids
	 * @return string[] ids of their rows in b_mail_message_uid
	 */
	private function buildMessageRowIds(string $dirPath, int $uidValidity, array $uids): array
	{
		$rowIds = [];

		foreach ($uids as $uid)
		{
			$rowIds[] = $this->buildMessageIdForDataBase($dirPath, $uidValidity, (int)$uid);
		}

		return $rowIds;
	}

	/**
	 * Neither the logger registry nor a channel of its own is used: a dropped message is an incident that
	 * has to reach the portal log unconditionally, while both a registry check and a dedicated channel
	 * would keep the record silent until someone configures them.
	 */
	protected function getHistorySyncLogger(): LoggerInterface
	{
		/*
			The factory always answers with a logger, so the return type holds - but the answer is an empty
			NullLogger on a portal where LOG_FILENAME is undefined, and there the record of a dropped
			message is lost without a trace. The whole module writes its diagnostics to that same file, so
			this is no worse than the module practice, yet the record is not guaranteed either.
		*/
		return $this->historySyncLogger ??= (new Main\Diag\LoggerFactory())
			->createById(self::HISTORY_SYNC_LOGGER_ID, [], isCheckEnabledFromRegistry: false);
	}

	private function logHistorySyncQuarantine(
		Mail\Internals\Entity\MailboxDirectory $dir,
		int $uid,
		int $attemptCount,
	): void
	{
		/*
			The values are named in the message itself: the standard formatter substitutes context by
			{token} and drops whatever has no token, so an identifier left out of the text is nowhere to
			be found in the log.
		*/
		$this->getHistorySyncLogger()->warning(
			'Mail history sync dropped a message it failed to download after {attemptCount} attempts:'
				. ' mailbox {mailboxId}, dir {dirPath}, uid {uid}.',
			[
				'mailboxId' => (int)$this->mailbox['ID'],
				'dirPath' => $dir->getPath(),
				'uid' => $uid,
				'attemptCount' => $attemptCount,
			],
		);
	}

	protected function isHistorySyncRequired(
		Mail\Internals\Entity\MailboxDirectory $dir,
		int $uidValidity,
	): bool
	{
		$boundary = $this->getMinimumSyncDate();
		if ($boundary === false)
		{
			return false;
		}

		return !static::isHistoryCoverageMarkerActual(
			$this->readHistoryCoverageMarker($dir),
			$uidValidity,
			(int)$boundary,
		);
	}

	/**
	 * @param int[]|string[] $uids
	 * @return array<int, true> uids of the dir that are registered as completed
	 */
	private function selectRegisteredUids(
		Mail\Internals\Entity\MailboxDirectory $dir,
		int $uidValidity,
		array $uids,
		Main\Type\DateTime $boundaryDate,
	): array
	{
		$result = $this->listMessages(
			array(
				'select' => array('MSG_UID'),
				/*
					The scope belongs inside: the uid of a folder is unique to the source generation that
					issued it, so a row of a retained generation must not answer for the uid the active
					source is being asked about - the message of the active source would never be fetched.
				*/
				'filter' => $this->getGenerationScope()->apply(array(
					'=DIR_MD5'  => $dir->getDirMd5(),
					'=DIR_UIDV' => $uidValidity,
					'=IS_OLD' => Mail\MailMessageUidTable::RECENT,
					// a row marked for deletion is hidden from the user, so its uid is still to be processed
					'==DELETE_TIME' => 0,
					'@MSG_UID'  => $uids,
					array(
						'LOGIC' => 'OR',
						array('>MESSAGE_ID' => 0),
						array('<INTERNALDATE' => $boundaryDate),
					),
				)),
			),
			false
		);

		$registered = [];
		while ($item = $result->fetch())
		{
			$registered[(int) $item['MSG_UID']] = true;
		}

		return $registered;
	}

	/**
	 * The messages of the period still waiting for their body: those already completed are excluded, and
	 * so are those that ran out of attempts.
	 *
	 * A completed row is the one with nothing left to do: the message is stored, or it is older than the
	 * period and was consciously skipped. The skip holds only while the period holds, so for a completed
	 * row without a message the date decides: an extended period brings such a message back. A message
	 * out of attempts leaves the remainder the same way - for the run both cases mean there is nothing to
	 * fetch here.
	 *
	 * Everything is done chunk by chunk on purpose. The list of a period runs into tens of thousands of
	 * uids, while counters exist for a few of them, so only the derived ids of one chunk are alive at a
	 * time instead of a map over the whole list.
	 *
	 * @param int[]|string[] $uids
	 * @param array<int, int> $attemptCountByUid - filled with uid => attempts made, for the uids of the
	 *        dir that have a counter, the dropped ones included: their counters are cleared as well when
	 *        the period gets covered.
	 * @return int[]|string[] uids to work on, in the order the server returned them
	 */
	protected function collectPendingUids(
		Mail\Internals\Entity\MailboxDirectory $dir,
		int $uidValidity,
		array $uids,
		int $boundary,
		array &$attemptCountByUid,
	): array
	{
		$attemptCountByUid = [];
		$pending = [];
		$attemptService = $this->getHistorySyncAttemptService();
		$boundaryDate = Main\Type\DateTime::createFromTimestamp($boundary);

		foreach (array_chunk($uids, 1000) as $chunk)
		{
			$registered = $this->selectRegisteredUids($dir, $uidValidity, $chunk, $boundaryDate);

			$rowIdByUid = [];
			foreach ($chunk as $uid)
			{
				if (!isset($registered[(int) $uid]))
				{
					$rowIdByUid[(int) $uid] = $this->buildMessageIdForDataBase($dir->getPath(), $uidValidity, (int) $uid);
				}
			}

			$attemptCounts = $attemptService->getAttemptCounts(array_values($rowIdByUid));

			foreach ($chunk as $uid)
			{
				$rowId = $rowIdByUid[(int) $uid] ?? null;

				if ($rowId === null)
				{
					continue;
				}

				$attemptCount = $attemptCounts[$rowId] ?? 0;

				if ($attemptCount > 0)
				{
					$attemptCountByUid[(int) $uid] = $attemptCount;
				}

				if (!HistorySyncAttemptService::isQuarantined($attemptCount))
				{
					$pending[] = $uid;
				}
			}
		}

		return $pending;
	}

	protected function readHistoryCoverageMarker(Mail\Internals\Entity\MailboxDirectory $dir): ?string
	{
		$row = Mail\Internals\MailEntityOptionsTable::getRow([
			'select' => ['VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailbox['ID'],
				'=ENTITY_TYPE' => 'DIR',
				'=ENTITY_ID' => $dir->getId(),
				'=PROPERTY_NAME' => self::HISTORY_SYNC_COVERAGE_PROPERTY,
			],
		]);

		return $row['VALUE'] ?? null;
	}

	/**
	 * A row marked by this pass lies in the middle of the interval of uids the dir is known to hold, and no
	 * pass asks the server about a uid it counts as synchronised - the walk over the period of the mailbox is
	 * the only one that can. Dropping the coverage of the dir sends that walk over the period once more, so a
	 * row marked by a short answer of the server meets its letter while the letter is still there.
	 */
	protected function dismissHistoryCoverage(string $dirPath): void
	{
		$dir = $this->getDirsHelper()->getDirByPath($dirPath);

		if ($dir === null)
		{
			return;
		}

		Mail\Internals\MailEntityOptionsTable::delete($this->getHistoryCoverageMarkerPrimary($dir));
	}

	protected function writeHistoryCoverageMarker(Mail\Internals\Entity\MailboxDirectory $dir, int $uidValidity, int $boundary): void
	{
		$keyRow = $this->getHistoryCoverageMarkerPrimary($dir);

		$value = static::buildHistoryCoverageMarkerValue($uidValidity, $boundary);

		if ($this->readHistoryCoverageMarker($dir) !== null)
		{
			Mail\Internals\MailEntityOptionsTable::update($keyRow, ['VALUE' => $value]);
		}
		else
		{
			Mail\Internals\MailEntityOptionsTable::add($keyRow + ['VALUE' => $value]);
		}
	}

	private function getHistoryCoverageMarkerPrimary(Mail\Internals\Entity\MailboxDirectory $dir): array
	{
		return [
			'MAILBOX_ID' => $this->mailbox['ID'],
			'ENTITY_TYPE' => 'DIR',
			'ENTITY_ID' => $dir->getId(),
			'PROPERTY_NAME' => self::HISTORY_SYNC_COVERAGE_PROPERTY,
		];
	}

	protected static function buildHistoryCoverageMarkerValue(int $uidValidity, int $boundary): string
	{
		return sprintf('covered_%u_%u', $uidValidity, $boundary);
	}

	protected static function isHistoryCoverageMarkerActual(?string $marker, int $uidValidity, int $currentBoundary): bool
	{
		if ($marker === null || !preg_match('/^covered_(\d+)_(\d+)$/', $marker, $matches))
		{
			return false;
		}

		/*
			The boundary only moves forward day by day; it becomes smaller only when the period
			is extended or the license limit grows - then the coverage has to be rebuilt.
		*/
		return (int) $matches[1] === $uidValidity && $currentBoundary >= (int) $matches[2];
	}

}
