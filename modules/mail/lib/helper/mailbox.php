<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail;
use Bitrix\Mail\Helper\Mailbox\MailboxSyncManager;
use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internal\Service\Mailbox\CrmImapGateReconciler;
use Bitrix\Mail\Internal\Service\Mailbox\MailboxEmailOccupancyService;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill;
use Bitrix\Mail\Internal\Service\SourceGeneration\ContextResolver;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MessageMatcher;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationMessageImporter;
use Bitrix\Mail\Internals\MessageUploadQueueTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Mail\MailServicesTable;
use Bitrix\Mail\Service\Message\SendFailure;
use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Mail\Helper;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Main\ORM\Query\Result;

abstract class Mailbox
{
	const SYNC_TIMEOUT = 300;
	const SYNC_TIME_QUOTA = 280;
	const MESSAGE_RESYNCHRONIZATION_TIME = 360;
	const INCOMPLETE_MESSAGE_REMOVE_TIMEOUT = 600;
	const MESSAGE_DELETION_LIMIT_AT_A_TIME = 500;
	const MESSAGE_SET_OLD_STATUS_LIMIT_AT_A_TIME = 500;
	const NUMBER_OF_BROKEN_MESSAGES_TO_RESYNCHRONIZE = 2;
	const NUMBER_OF_INCOMPLETE_MESSAGES_TO_REMOVE = 10;
	private const MAX_OUTGOING_ATTEMPTS = 5;
	private const OUTGOING_AUTHOR_OPTION = 'outgoingAuthorId';

	const MAIL_SERVICES_ONLY_FOR_THE_RU_ZONE = [
		'yandex',
		'mail.ru',
	];

	/*
		Services a mailbox is never connected to by hand: it gets there by moving to our hosting, and
		its credentials are issued outside. A tile in the showcase would open a form that ends nowhere.
		Hiding happens here, in the showcase list only - a mailbox already on such a service reads its
		row directly and keeps the name and the icon.
	*/
	const MAIL_SERVICES_HIDDEN_FROM_THE_SHOWCASE = [
		'mailhost',
	];

	/*
		Why a run of the synchronization refused to start. It answers with a count of
		letters, so all of these look alike from the outside - a caller that must not go
		on without a completed pass reads the reason through getLastSyncRefusal().
	*/
	public const SYNC_REFUSAL_LICENSE_DENIED = 'LICENSE_DENIED';
	public const SYNC_REFUSAL_SYNC_LOCKED = 'SYNC_LOCKED';
	public const SYNC_REFUSAL_TIME_QUOTA = 'TIME_QUOTA';
	public const SYNC_REFUSAL_DB_LOCK_LOST = 'DB_LOCK_LOST';
	public const SYNC_REFUSAL_GENERATION_DENIED = 'GENERATION_DENIED';

	/**
	 * @var array uids of the last existence check whose request the mail server answered with an error, so
	 *      that nothing is known about them. Filled by the check and read right after it; an implementation
	 *      that never fills it is taken to have answered for the whole sample, as it was before.
	 */
	protected array $unansweredUidsOfLastCheck = [];

	protected $dirsMd5WithCounter;
	protected $mailbox;
	protected $dirsHelper;
	protected ?string $dirsHelperScopeKey = null;
	protected $filters;
	protected $session;
	protected $startTime, $syncTimeout, $checkpoint;
	protected $syncParams = [];
	protected $errors, $warnings;
	private ?string $lastSyncRefusal = null;
	private bool $mailboxLockHeldByCaller = false;
	protected ?Context $generationContext = null;
	protected ?MessageMatcher $messageMatcher = null;
	protected ?MigrationMessageImporter $migrationImporter = null;
	protected $lastSyncResult = [
		'newMessages' => 0,
		'newMessagesNotify' => 0,
		'deletedMessages' => 0,
		'updatedMessages' => 0,
		'newMessageId' => null,
	];

	public static function isRuZone(): bool
	{
		return Loader::includeModule('bitrix24')
			? in_array(\CBitrix24::getPortalZone(), ['ru', 'kz', 'by'])
			: in_array(LANGUAGE_ID, ['ru', 'kz', 'by']);
	}

	public static function getServices(): array
	{
		$res = MailServicesTable::getList([
			'filter' => [
				'=ACTIVE' => 'Y',
				'=SITE_ID' => SITE_ID,
			],
			'order' => [
				'SORT' => 'ASC',
				'NAME' => 'ASC',
			],
		]);

		$services = [];

		while ($service = $res->fetch())
		{
			if (in_array($service['NAME'], self::MAIL_SERVICES_HIDDEN_FROM_THE_SHOWCASE, true))
			{
				continue;
			}

			if(!self::isRuZone() && in_array($service['NAME'], self::MAIL_SERVICES_ONLY_FOR_THE_RU_ZONE, true))
			{
				continue;
			}

			$serviceFinal = [
				'id' => $service['ID'],
				'type' => $service['SERVICE_TYPE'],
				'name' => $service['NAME'],
				'link' => $service['LINK'],
				'icon' => MailServicesTable::getIconSrc($service['NAME']),
				'server' => $service['SERVER'],
				'port' => $service['PORT'],
				'encryption' => $service['ENCRYPTION'],
				'token' => $service['TOKEN'],
				'flags' => $service['FLAGS'],
				// Told apart from the raw flags on purpose: the client must not do the bit arithmetic.
				'externalCredentials' => MailServicesTable::hasExternalCredentials($service),
				'sort' => $service['SORT'],
			];

			$services[] = $serviceFinal;
		}

		return $services;
	}

	/**
	 * Creates active mailbox helper instance by ID
	 *
	 * @param int $id Mailbox ID.
	 * @param bool $throw Throw exception on error.
	 * @return \Bitrix\Mail\Helper\Mailbox|false
	 * @throws \Exception
	 */
	public static function createInstance($id, $throw = true): Mailbox|bool
	{
		return static::rawInstance(array('=ID' => (int) $id, '=ACTIVE' => 'Y'), $throw);
	}

	/**
	 * Creates the sync engine of one concrete source generation of a mailbox.
	 *
	 * The generation table is the source of truth for the IMAP credentials, and only the
	 * ACTIVE generation is projected into the connection fields of b_mail_mailbox. So an
	 * engine that has to talk to another generation of the same mailbox (a PREPARING one
	 * being imported) receives its connection snapshot here and works under the trusted
	 * context of that generation.
	 *
	 * @param array $connection The snapshot of the generation: SERVER, PORT, USE_TLS, LOGIN,
	 *                          PASSWORD, SERVICE_ID. Nothing else of the mailbox row changes.
	 * @return \Bitrix\Mail\Helper\Mailbox|false
	 * @throws \Exception
	 */
	public static function createGenerationInstance(int $mailboxId, Context $context, array $connection)
	{
		if ($context->mailboxId !== $mailboxId)
		{
			throw new Main\ArgumentException('The generation context belongs to another mailbox', 'context');
		}

		$mailbox = static::prepareMailbox(['=ID' => $mailboxId, '=ACTIVE' => 'Y']);
		if (empty($mailbox))
		{
			return false;
		}

		foreach (['SERVICE_ID', 'SERVER', 'PORT', 'USE_TLS', 'LOGIN', 'PASSWORD'] as $field)
		{
			if (isset($connection[$field]))
			{
				$mailbox[$field] = $connection[$field];
			}
		}

		$instance = static::instance($mailbox);
		if ($instance instanceof Mailbox)
		{
			$instance->setGenerationContext($context);
		}

		return $instance;
	}

	public function getDirsMd5WithCounter($mailboxId)
	{
		if($this->dirsMd5WithCounter)
		{
			return $this->dirsMd5WithCounter;
		}

		$countersById = [];
		$counterResult = Mail\Internals\MailCounterTable::getList([
			'select' => [
				'DIRECTORY_ID' => 'ENTITY_ID',
				'UNSEEN' => 'VALUE',
			],
			'filter' => [
				'=ENTITY_TYPE' => 'DIR',
				'=MAILBOX_ID' => $mailboxId,
			],
		]);
		while ($item = $counterResult->fetch()) {
			$countersById[(int)$item['DIRECTORY_ID']] = (int)$item['UNSEEN'];
		}

		if (empty($countersById)) {
			return [];
		}

		$directoriesWithCounter = [];
		$query = Mail\Internals\MailboxDirectoryTable::query()
			->whereIn('ID', array_keys($countersById))
			->setSelect([
				'ID',
				'DIR_MD5',
				'MESSAGE_COUNT',
			])
			->where('MAILBOX_ID', $mailboxId)
		;

		/*
			The map is keyed by the folder path hash, and a folder of another generation answers to
			the same hash as the folder serving the user now - the one retained by an earlier switch
			and the one of a generation being prepared alike. The scope of the folders is the same
			one the tree of the mailbox is read by, so the counters describe the folders the user is
			looking at and not another epoch of them.
		*/
		foreach ($this->peekDirectoryScope()->apply([]) as $condition => $value)
		{
			$query->addFilter($condition, $value);
		}

		$res = $query->exec();
		while ($item = $res->fetch())
		{
			$id = $item['ID'];
			$dirMd5 = $item['DIR_MD5'];
			$directoriesWithCounter[$dirMd5] = [
				'MESSAGE_COUNT' => $item['MESSAGE_COUNT'],
				'UNSEEN' => $countersById[$id] ?? 0,
				'DIR_MD5' => $dirMd5,
				'ID' => $item['ID'],
			];
		}

		$this->dirsMd5WithCounter = $directoriesWithCounter;

		return $directoriesWithCounter;
	}

	public function sendCountersEvent()
	{
		\CPullWatch::addToStack(
			'mail_mailbox_' . $this->mailbox['ID'],
			[
				'params' => [
					'mailboxId' => $this->mailbox['ID'],
					'dirs' => $this->getDirsWithUnseenMailCounters(),
				],
				'module_id' => 'mail',
				'command' => 'counters_is_synchronized',
			]
		);
		\Bitrix\Pull\Event::send();
	}

	public function getDirsWithUnseenMailCounters()
	{
		global $USER;
		$mailboxId = $this->mailbox['ID'];

		if (!Helper\Message::isMailboxOwner($mailboxId, $USER->GetID()))
		{
			return false;
		}

		$syncDirs = $this->getDirsHelper()->getSyncDirs();
		$defaultDirPath = $this->getDirsHelper()->getDefaultDirPath();
		$dirs = [];

		$dirsMd5WithCountOfUnseenMails = $this->getDirsMd5WithCounter($mailboxId);

		$defaultDirPathId = null;

		foreach ($syncDirs as $dir)
		{
			$newDir = [];
			$newDir['path'] = $dir->getPath(true);
			$newDir['name'] = $dir->getName();
			$newDir['count'] = 0;
			$currentDirMd5WithCountsOfUnseenMails = $dirsMd5WithCountOfUnseenMails[$dir->getDirMd5()];

			if ($currentDirMd5WithCountsOfUnseenMails !== null)
			{
				$newDir['count'] = $currentDirMd5WithCountsOfUnseenMails['UNSEEN'];
			}

			if($newDir['path'] === $defaultDirPath)
			{
				$defaultDirPathId = count($dirs);
			}

			$dirs[] = $newDir;
		}

		if (empty($dirs))
		{
			$dirs = [
				[
					'count' => 0,
					'path' => $defaultDirPath,
					'name' => $defaultDirPath,
				],
			];
		}

		//inbox always on top
		array_unshift( $dirs, array_splice($dirs, $defaultDirPathId, 1)[0] );

		return $dirs;
	}

	/**
	 * Creates mailbox helper instance
	 *
	 * @param mixed $filter Filter.
	 * @param bool $throw Throw exception on error.
	 * @return \Bitrix\Mail\Helper\Mailbox|false
	 * @throws \Exception
	 */
	public static function rawInstance($filter, $throw = true)
	{
		try
		{
			$mailbox = static::prepareMailbox($filter);

			return static::instance($mailbox);
		}
		catch (\Exception $e)
		{
			if ($throw)
			{
				throw $e;
			}
			else
			{
				return false;
			}
		}
	}

	protected static function instance(array $mailbox)
	{
		// @TODO: other SERVER_TYPE
		$types = array(
			'imap' => 'Bitrix\Mail\Helper\Mailbox\Imap',
			'controller' => 'Bitrix\Mail\Helper\Mailbox\Imap',
			'domain' => 'Bitrix\Mail\Helper\Mailbox\Imap',
			'crdomain' => 'Bitrix\Mail\Helper\Mailbox\Imap',
		);

		if (empty($mailbox))
		{
			return false;
		}

		if (empty($mailbox['SERVER_TYPE']) || !array_key_exists($mailbox['SERVER_TYPE'], $types))
		{
			throw new Main\ObjectException('unsupported mailbox type');
		}

		return new $types[$mailbox['SERVER_TYPE']]($mailbox);
	}

	public static function prepareMailbox($filter)
	{
		if (is_scalar($filter))
		{
			$filter = array('=ID' => (int) $filter);
		}

		static $cachedMailboxes = [];

		$cacheKey = null;

		//For additional security purposes
		if (isset($filter['=ID']))
		{
			$cacheKey = md5(serialize($filter)).'-'.$filter['=ID'];
		}

		if (is_null($cacheKey) || !isset($cachedMailboxes[$cacheKey]))
		{
			$mailbox = Mail\MailboxTable::getList([
				'filter' => $filter,
				'select' => [
					'*',
					'LANG_CHARSET' => 'SITE.CULTURE.CHARSET'
				],
				'limit' => 1,
			])->fetch() ?: [];

			if (!is_null($cacheKey))
			{
				$cachedMailboxes[$cacheKey] = $mailbox;
			}
		}
		else
		{
			$mailbox = $cachedMailboxes[$cacheKey];
		}

		if (!empty($mailbox))
		{
			if (in_array($mailbox['SERVER_TYPE'], array('controller', 'crdomain', 'domain')))
			{
				$result = \CMailDomain2::getImapData(); // @TODO: request controller for 'controller' and 'crdomain'

				$mailbox['SERVER']  = $result['server'];
				$mailbox['PORT']    = $result['port'];
				$mailbox['USE_TLS'] = $result['secure'];
			}

			Mail\MailboxTable::normalizeEmail($mailbox);
		}

		return $mailbox;
	}

	public function setSyncParams(array $params = array())
	{
		$this->syncParams = $params;
	}

	protected function __construct($mailbox)
	{
		$this->startTime = time();
		if (defined('START_EXEC_PROLOG_BEFORE_1'))
		{
			$startTime = 0;
			if (is_float(START_EXEC_PROLOG_BEFORE_1))
			{
				$startTime = START_EXEC_PROLOG_BEFORE_1;
			}
			elseif (preg_match('/ (\d+)$/', START_EXEC_PROLOG_BEFORE_1, $matches))
			{
				$startTime = $matches[1];
			}

			if ($startTime > 0 && $this->startTime > $startTime)
			{
				$this->startTime = $startTime;
			}
		}

		$this->syncTimeout = static::getTimeout();

		$this->mailbox = $mailbox;

		$this->normalizeMailboxOptions();

		$this->setCheckpoint();

		$this->session = md5(uniqid(''));
		$this->errors = new Main\ErrorCollection();
		$this->warnings = new Main\ErrorCollection();
	}

	protected function normalizeMailboxOptions()
	{
		if (empty($this->mailbox['OPTIONS']) || !is_array($this->mailbox['OPTIONS']))
		{
			$this->mailbox['OPTIONS'] = array();
		}
	}

	public function renewOauthTokens(): bool
	{
		if (empty($this->mailbox['PASSWORD']))
		{
			return false;
		}

		$oauth = OAuth::getInstanceByMeta($this->mailbox['PASSWORD']);

		if (empty($oauth))
		{
			return false;
		}

		return $oauth->renewTokens();
	}

	public function getMailbox()
	{
		return $this->mailbox;
	}

	public function getServiceId(): int
	{
		return (int)($this->mailbox['SERVICE_ID'] ?? 0);
	}

	public function getProviderCode(): string
	{
		$serviceId = $this->getServiceId();
		$service = $serviceId > 0
			? MailServicesTable::getByPrimary(
				$serviceId,
				['select' => ['NAME'], 'cache' => ['ttl' => 86400]],
			)->fetch()
			: \Bitrix\Mail\Internal\Service\SourceGeneration\ActiveMailboxService::resolve($this->mailbox)
		;

		if ($service && !empty($service['NAME']))
		{
			return mb_strtolower($service['NAME']);
		}

		return '';
	}

	public function getMailboxId(): int
	{
		$mailbox = self::getMailbox();

		if (isset($mailbox['ID']))
		{
			return (int) $mailbox['ID'];
		}

		return 0;
	}

	public function getMailboxOwnerId(): int
	{
		$mailbox = self::getMailbox();

		if (isset($mailbox['USER_ID']))
		{
			return (int) $mailbox['USER_ID'];
		}

		return 0;
	}

	/**
	 * Returns the source generation context of this helper run, resolving it once (TPL-01).
	 * Regular entries get the ACTIVE generation of the mailbox.
	 *
	 * @throws Main\SystemException
	 */
	public function getGenerationContext(): Context
	{
		if ($this->generationContext === null)
		{
			$this->generationContext = (new ContextResolver())->resolveForSync($this->mailbox);
		}

		return $this->generationContext;
	}

	/**
	 * Injects a trusted context (the migration import entry resolves a concrete
	 * PREPARING generation). Allowed only before the default context is resolved.
	 *
	 * @throws Main\ArgumentException
	 * @throws Main\InvalidOperationException
	 */
	public function setGenerationContext(Context $context): void
	{
		if ($context->mailboxId !== $this->getMailboxId())
		{
			throw new Main\ArgumentException('The generation context belongs to another mailbox', 'context');
		}

		if ($this->generationContext !== null)
		{
			throw new Main\InvalidOperationException('The generation context of this run is already resolved');
		}

		$this->generationContext = $context;
	}

	/**
	 * Hands the matcher of the caller to this run, before it starts. An orchestrator that
	 * has already completed the candidate fingerprints of the mailbox shares what it knows
	 * that way, instead of letting the run rebuild the same walk over the history.
	 */
	public function useMessageMatcher(MessageMatcher $matcher): void
	{
		$this->messageMatcher = $matcher;
	}

	/**
	 * The matcher of the messages of an imported generation against the local
	 * history (ALG-01). One instance per run: it remembers the mailboxes whose
	 * candidate fingerprints are already built.
	 */
	protected function getMessageMatcher(): MessageMatcher
	{
		return $this->messageMatcher ??= new MessageMatcher();
	}

	/**
	 * The persistence of a migration import (DATA-01): the physical row, the
	 * matching result and the fingerprints of one imported letter are closed by it.
	 */
	protected function getMigrationImporter(): MigrationMessageImporter
	{
		return $this->migrationImporter ??= new MigrationMessageImporter($this->getMessageMatcher());
	}

	/**
	 * Moves the lease of the sync lock this pass holds to now, by a compare-and-set on the value it
	 * took. False when the value in the database is not ours any more.
	 */
	private function renewSyncLock(): bool
	{
		$connection = Main\Application::getConnection();
		$held = (int)$this->mailbox['SYNC_LOCK'];
		$now = time();

		if ($now === $held)
		{
			// An update to the same value changes no row, so the ownership is asked instead
			return (int)$connection->queryScalar(sprintf(
				'SELECT SYNC_LOCK FROM b_mail_mailbox WHERE ID = %u',
				(int)$this->mailbox['ID'],
			)) === $held;
		}

		$connection->queryExecute(sprintf(
			'UPDATE b_mail_mailbox SET SYNC_LOCK = %u WHERE ID = %u AND SYNC_LOCK = %u',
			$now,
			(int)$this->mailbox['ID'],
			$held,
		));

		if ($connection->getAffectedRowsCount() !== 1)
		{
			return false;
		}

		$this->mailbox['SYNC_LOCK'] = $now;

		return true;
	}

	/**
	 * The generation scope of the physical queries of this run (folders, uid
	 * lookups by coordinates, queues). Forces the context resolution: sync flows
	 * call it after the entry guard has already validated the context.
	 *
	 * @throws Main\SystemException
	 */
	protected function getGenerationScope(): GenerationScope
	{
		return GenerationScope::fromContext($this->getGenerationContext());
	}

	/**
	 * The generation scope of this run without forcing the context resolution:
	 * read-only paths (directory helpers, status counters) must not fail on a
	 * stale pointer before their own guards do. Equals getGenerationScope() for
	 * a run whose context is already resolved or injected.
	 */
	protected function peekGenerationScope(): GenerationScope
	{
		return $this->generationContext !== null
			? GenerationScope::fromContext($this->generationContext)
			: GenerationScope::forMailbox((int)$this->mailbox['ID'])
		;
	}

	/**
	 * The scope of the folders of this run. For a run carrying a context it is the scope above
	 * - an import reads and writes the folders of the generation it prepares - and for a run
	 * without one it hides the generations the mailbox is preparing: their folders are written
	 * when a snapshot is accepted, before the pass that moves the pointer, so an unfiltered
	 * read would show the user the tree of a source nobody handed the mailbox over to.
	 *
	 * The letters of this run keep {@see peekGenerationScope()} on purpose. In that same window
	 * a prepared generation has folders and not a single placement - the import of the letters
	 * starts after the pointer has moved - so the reads of the letters have nothing to hide.
	 */
	protected function peekDirectoryScope(): GenerationScope
	{
		return $this->generationContext !== null
			? GenerationScope::fromContext($this->generationContext)
			: GenerationScope::servingMailbox((int)$this->mailbox['ID'])
		;
	}

	/**
	 * Validates the generation context of a regular sync entry before any IMAP call.
	 * An unknown or stale generation, or a context without full access, aborts the run.
	 */
	protected function checkSyncGenerationContext(): bool
	{
		try
		{
			$context = $this->getGenerationContext();
		}
		catch (Main\SystemException $exception)
		{
			$this->registerGenerationContextError(
				new Main\Error($exception->getMessage(), 'MAIL_SOURCE_GENERATION_INVALID'),
			);

			return false;
		}

		if (!$context->canWrite())
		{
			$this->registerGenerationContextError(new Main\Error(
				sprintf(
					'The source generation %u (%s) of the mailbox %u does not accept a regular sync',
					$context->generationId,
					$context->status,
					$context->mailboxId,
				),
				'MAIL_SOURCE_GENERATION_DENIED',
			));

			return false;
		}

		return true;
	}

	private function registerGenerationContextError(Main\Error $error): void
	{
		$this->errors->setError($error);
		// quickSync reports a failed dir sync through the warnings collection
		$this->warnings->setError($error);
	}

	/*
	Additional check that the quota has not been exceeded
	since the actual creation of the mailbox instance in php
	*/
	protected function isTimeQuotaExceeded()
	{
		return time() - $this->startTime > ceil(static::getTimeout() * 0.9);
	}

	public function setCheckpoint()
	{
		$this->checkpoint = time();
	}

	public function updateGlobalCounter($userId)
	{
		Message::resetCountersCache((int)$userId);
		Message::setUserUnseenCounter((int)$userId, $this->mailbox['LID']);
	}

	public function updateGlobalCounterForCurrentUser()
	{
		$this->updateGlobalCounter($this->mailbox['USER_ID']);
	}

	private function findMessagesWithAnEmptyBody(int $count, $mailboxId)
	{
		$reSyncTime = (new Main\Type\DateTime())->add('- '.static::MESSAGE_RESYNCHRONIZATION_TIME.' seconds');

		$ids = Mail\Internals\MailEntityOptionsTable::getList(
			[
				'select' => ['ENTITY_ID'],
				'filter' =>
					[
						'=MAILBOX_ID' => $mailboxId,
						'=ENTITY_TYPE' => 'MESSAGE',
						'=PROPERTY_NAME' => 'UNSYNC_BODY',
						'=VALUE' => 'Y',
						'<=DATE_INSERT' => $reSyncTime,
					]
				,
				'limit' => $count,
			]
		)->fetchAll();

		return array_map(
			function ($item)
			{
				return $item['ENTITY_ID'];
			},
			$ids
		);
	}

	private function getLostMessages(int $count, array $additionalFilters = []): Main\ORM\Query\Result
	{
		return MailMessageUidTable::getList([
			'select' => array(
				'MSG_UID',
				'DIR_MD5',
			),
			'filter' => $this->getGenerationScope()->apply(array_merge([
				'=MAILBOX_ID' => $this->mailbox['ID'],
				'=IS_OLD' => \Bitrix\Mail\MailMessageUidTable::LOST,
			], $additionalFilters)),
			'limit' => $count,
		]);
	}

	/**
	 * @param array|null $additionalFilters null when the borders of the dir are unknown: the removal
	 * has nothing to narrow it down to the dir and to the interval the server holds, while the row it
	 * deletes is the only trace of a letter whose body is not downloaded yet.
	 */
	private function removeOldUnderloadedMessages(int $limit, ?array $additionalFilters = []): bool
	{
		if ($additionalFilters === null)
		{
			return false;
		}

		$resyncTime = new Main\Type\DateTime();
		$resyncTime->add('- '.static::INCOMPLETE_MESSAGE_REMOVE_TIMEOUT.' seconds');

		return MailMessageUidTable::deleteList(
			$this->getGenerationScope()->apply(array_merge([
				'=MAILBOX_ID' => $this->mailbox['ID'],
				'=MESSAGE_ID' => '0',
				'=IS_OLD' => \Bitrix\Mail\MailMessageUidTable::DOWNLOADED,
				'<=DATE_INSERT' => $resyncTime,
			], $additionalFilters)),
			limit: $limit,
			sendEvent: false
		);
	}

	/**
	 * The dirs holding a row the consistency run has work with, by the md5 of the dir path.
	 *
	 * Asked in one query for the whole mailbox and asked first, because the borders of a dir are
	 * learned from the server - a SELECT and a FETCH per dir - and they are needed by one thing only:
	 * narrowing the removal down to the dir and to the interval the server holds. A dir with nothing
	 * to remove and nothing to recover needs no borders, and a mailbox in order has no such dirs at
	 * all: an underloaded row is a download that broke off, a lost row is a letter the sync could not
	 * name. Asking the server about every dir of every run to find that out costs the run its clock.
	 *
	 * @return array<string, true>
	 */
	private function getDirsWithConsistencyWork(): array
	{
		$resyncTime = new Main\Type\DateTime();
		$resyncTime->add('- '.static::INCOMPLETE_MESSAGE_REMOVE_TIMEOUT.' seconds');

		$rows = MailMessageUidTable::getList([
			'select' => ['DIR_MD5'],
			'filter' => $this->getGenerationScope()->apply([
				'=MAILBOX_ID' => $this->mailbox['ID'],
				[
					'LOGIC' => 'OR',
					[
						'=MESSAGE_ID' => '0',
						'=IS_OLD' => MailMessageUidTable::DOWNLOADED,
						'<=DATE_INSERT' => $resyncTime,
					],
					['=IS_OLD' => MailMessageUidTable::LOST],
				],
			]),
			'group' => ['DIR_MD5'],
		]);

		$dirs = [];

		while ($row = $rows->fetch())
		{
			$dirs[$row['DIR_MD5']] = true;
		}

		return $dirs;
	}

	private function syncIncompleteMessages(Main\ORM\Query\Result $messages): void
	{
		$mailboxId = $this->mailbox['ID'];

		while ($item = $messages->fetch())
		{
			$dirPath = $this->getDirsHelper()->getDirPathByHash($item['DIR_MD5']);
			$this->syncMessages(
				$mailboxId,
				$dirPath,
				[$item['MSG_UID']],
				true,
				true,
				true,
				true,
			);

			if(Main\Loader::includeModule('pull'))
			{
				\CPullWatch::addToStack(
					'mail_mailbox_' . $mailboxId,
					[
						'params' => [
							'dir' => $dirPath,
							'mailboxId' => $mailboxId,
						],
						'module_id' => 'mail',
						'command' => 'recovered_message_is_synchronized',
					]
				);
				\Bitrix\Pull\Event::send();
			}
		}
	}

	public function reSyncStartPage(): void
	{
		$this->resyncDir($this->getDirsHelper()->getDefaultDirPath(),25);
	}

	public function restoringConsistency(): void
	{
		$dirsWithWork = $this->getDirsWithConsistencyWork();
		$dirsSync = $dirsWithWork === [] ? [] : $this->getDirsHelper()->getSyncDirsOrderByTime();

		foreach ($dirsSync as $dir)
		{
			if (!isset($dirsWithWork[$dir->getDirMd5()]))
			{
				continue;
			}

			if ($this->isTimeQuotaExceeded())
			{
				// The dirs left keep their work for the next hit: the letters of the mailbox come first
				break;
			}

			$messageInFolderFilter = $this->getMessageInFolderFilter($dir);
			$this->removeOldUnderloadedMessages(static::NUMBER_OF_INCOMPLETE_MESSAGES_TO_REMOVE, $messageInFolderFilter);

			// The recovery below only reads rows and queues their letters for a resync, so unknown borders
			// cost it nothing but the narrowing: leaving it out would keep lost letters lost instead
			$this->syncIncompleteMessages($this->getLostMessages(
				static::NUMBER_OF_BROKEN_MESSAGES_TO_RESYNCHRONIZE,
				$messageInFolderFilter ?? [],
			));
		}



		\Bitrix\Mail\Helper\Message::reSyncBody($this->mailbox['ID'], $this->findMessagesWithAnEmptyBody(static::NUMBER_OF_BROKEN_MESSAGES_TO_RESYNCHRONIZE, $this->mailbox['ID']));
	}

	public function syncCounters(): void
	{
		Helper::setMailboxUnseenCounter($this->mailbox['ID'], Helper::updateMailCounters($this->mailbox));

		$usersWithAccessToMailbox = Mailbox\SharedMailboxesManager::getUserIdsWithAccessToMailbox($this->mailbox['ID']);

		foreach ($usersWithAccessToMailbox as $userId)
		{
			$this->updateGlobalCounter($userId);
		}
	}

	/**
	 * @param $id
	 * @param $dir
	 * @param $onlySyncCurrent
	 * @return Main\Result
	 * @throws Main\LoaderException
	 */
	public static function quickSync($id, $dir = null, $onlySyncCurrent = false): Main\Result
	{
		$finalResult = new \Bitrix\Main\Result();

		$sessionId = md5(uniqid(''));

		$response = array(
			'complete' => false,
			'status' => 0,
			'sessid' => $sessionId,
			'timestamp' => microtime(true),
			'final' => true,
			'is_fatal_error' => true,
		);

		$finalResult->setData($response);

		if(!Loader::includeModule('mail'))
		{
			$finalResult->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MODULE_NOT_INSTALLED_1')));

			//Stop attempts to resynchronize the mailbox
			$response['is_fatal_error'] = true;
			$response['complete'] = true;

			$finalResult->setData($response);

			return $finalResult;
		}

		if (!LicenseManager::isSyncAvailable())
		{
			$response['complete'] = true;
			$response['is_fatal_error'] = true;

			$finalResult->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_SYNC_NOT_AVAILABLE_1')));
			$finalResult->setData($response);

			return $finalResult;
		}

		$ownerId = 0;

		if (MailboxAccess::hasCurrentUserAnyAccessToMailbox($id))
		{
			$ownerId = MailboxTable::getOwnerId($id);
		}

		$mailboxHelper = Helper\Mailbox::createInstance($id);

		if ($ownerId > 0 && !empty($mailboxHelper))
		{
			session_write_close();

			if (is_null($dir))
			{
				$dir = $mailboxHelper->getDirsHelper()->getDefaultDirPath(true);
			}

			$mailboxHelper->setSyncParams(array(
				'full' => true,
				'currentDir' => $dir,
				'sessid' => $sessionId,
			));

			$mailboxSyncManager = new MailboxSyncManager($ownerId);
			$mailboxSyncManager->setSyncStartedData($id);

			$result = $mailboxHelper->syncDir($dir);

			$response['timestamp'] = microtime(true);

			if ($result === false)
			{
				$mailboxSyncManager->setSyncStatus($id, false, time());

				$response['complete'] = true;
				$response['is_fatal_error'] = true;

				$finalResult->addErrors($mailboxHelper->getWarnings()->toArray());
			}
			else
			{
				/*
					If the directory is not locked for synchronization,
					then we will resynchronize the old messages
					(delete the missing messages, synchronize the readability statuses).
				*/
				if ($result !== null)
				{
					$response['new'] = $result;

					$lastSyncResult = $mailboxHelper->getLastSyncResult();

					$response['updated'] = -$lastSyncResult['updatedMessages'];
					$response['deleted'] = -$lastSyncResult['deletedMessages'];

					$mailboxHelper->resyncDir($dir);

					$lastSyncResult = $mailboxHelper->getLastSyncResult();

					$response['updated'] += $lastSyncResult['updatedMessages'];
					$response['deleted'] += $lastSyncResult['deletedMessages'];

					$response['timestamp'] = microtime(true);
				}

				$onlySyncCurrent = filter_var($onlySyncCurrent, FILTER_VALIDATE_BOOLEAN);

				if (!$onlySyncCurrent && count($mailboxHelper->getDirsHelper()->getSyncDirs()) > 1)
				{
					//If resynchronization of the entire mailbox is started
					if($mailboxHelper->sync(false))
					{
						$response['complete'] = true;
					}
				}
				else
				{
					$mailboxSyncManager->setSyncStatus($id, true, time());
					$mailboxHelper->notifyNewMessages();
					$response['complete'] = true;
				}
			}
		}
		else
		{
			$response['complete'] = true;
			$response['is_fatal_error'] = true;
			$finalResult->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_THE_MAILBOX_HAS_BEEN_DELETED_1')));
		}

		if ($ownerId > 0 && ($response['new'] > 0 || $response['deleted'] > 0 || $response['updated'] > 0))
		{
			$mailboxHelper->syncCounters();
			$mailboxHelper->sendCountersEvent();
		}

		$finalResult->setData($response);

		return $finalResult;
	}

	/**
	 * One pass of the synchronization under the mailbox lock the caller already holds and
	 * goes on holding afterwards.
	 *
	 * The hand-over of a mailbox to another physical source keeps the mailbox to itself
	 * from the final synchronization of the retained source to the switch, so the pass must
	 * neither refuse itself on that very lock nor release it at the end.
	 *
	 * @see sync()
	 */
	public function syncUnderHeldMailboxLock($syncCounters = true)
	{
		$this->mailboxLockHeldByCaller = true;

		try
		{
			return $this->sync($syncCounters);
		}
		finally
		{
			$this->mailboxLockHeldByCaller = false;
		}
	}

	/**
	 * Why the last {@see sync()} refused to start, null when it ran.
	 *
	 * The refusals are told apart by the SYNC_REFUSAL_* codes of this class: the returned
	 * count of letters cannot tell them apart, and the signature of sync() is relied upon
	 * far outside this domain.
	 */
	public function getLastSyncRefusal(): ?string
	{
		return $this->lastSyncRefusal;
	}

	/**
	 * Whether the license of the portal lets this mailbox synchronize at all.
	 */
	protected function isSyncAllowedByLicense(): bool
	{
		return LicenseManager::isSyncAvailable()
			&& LicenseManager::checkTheMailboxForSyncAvailability(
				(int)$this->mailbox['ID'],
				(int)$this->mailbox['USER_ID'],
			)
		;
	}

	public function sync($syncCounters = true)
	{
		global $DB;

		$this->lastSyncRefusal = null;

		/*
			Setting a new time for an attempt to synchronize the mailbox
			through the agent for users with a free tariff
		*/
		if (!$this->isSyncAllowedByLicense())
		{
			$this->mailbox['OPTIONS']['next_sync'] = time() + 3600 * 24;
			$this->lastSyncRefusal = static::SYNC_REFUSAL_LICENSE_DENIED;

			return 0;
		}

		/*
		Do not start synchronization if no more than static::getTimeout() have passed since the previous one
		*/
		if (!$this->mailboxLockHeldByCaller && time() - $this->mailbox['SYNC_LOCK'] < static::getTimeout())
		{
			$this->lastSyncRefusal = static::SYNC_REFUSAL_SYNC_LOCKED;

			return 0;
		}

		$this->mailbox['SYNC_LOCK'] = time();

		/*
		Additional check that the quota has not been exceeded
		since the actual creation of the mailbox instance in php
		*/
		if ($this->isTimeQuotaExceeded())
		{
			$this->lastSyncRefusal = static::SYNC_REFUSAL_TIME_QUOTA;

			return 0;
		}

		// An unknown or stale generation must fail before any IMAP call
		if (!$this->checkSyncGenerationContext())
		{
			$this->mailbox['OPTIONS']['next_sync'] = time() + 3600;
			$this->lastSyncRefusal = static::SYNC_REFUSAL_GENERATION_DENIED;

			return false;
		}

		$crmImapGateResult = $this->ensureCrmImapGateReady();
		if (!$crmImapGateResult->isSuccess())
		{
			$this->registerCrmImapGateErrors($crmImapGateResult);

			return false;
		}

		/*
			The lock is taken in the database before anything of the mailbox changes, and not only asked
			about: the value this instance holds was loaded when the instance was created, and the three
			calls below already change the data of the mailbox - the outgoing upload with the folder sync
			that removes the folders the server does not list, the consistency repair and the resync of
			the start page. Taken only after them, the lock left those three beside a hand-over or the
			acceptance of a snapshot that hold it, and the folders a prepared generation had just written
			could be removed. Losing the exchange here refuses the pass as a busy mailbox.
		*/
		$lockSql = sprintf(
			'UPDATE b_mail_mailbox SET SYNC_LOCK = %u WHERE ID = %u AND (SYNC_LOCK IS NULL OR SYNC_LOCK < %u)',
			$this->mailbox['SYNC_LOCK'], $this->mailbox['ID'], $this->mailbox['SYNC_LOCK'] - static::getTimeout()
		);

		if (!$this->mailboxLockHeldByCaller && !$DB->query($lockSql)->affectedRowsCount())
		{
			$this->lastSyncRefusal = static::SYNC_REFUSAL_SYNC_LOCKED;

			return 0;
		}

		$this->session = md5(uniqid(''));

		try
		{
			$this->syncOutgoing();
			$this->restoringConsistency();
			$this->reSyncStartPage();
		}
		catch (\Throwable $exception)
		{
			// A lock taken by this pass is given back, or the mailbox stays refused until it expires
			if (!$this->mailboxLockHeldByCaller)
			{
				$DB->query(sprintf(
					'UPDATE b_mail_mailbox SET SYNC_LOCK = 0 WHERE ID = %u AND SYNC_LOCK = %u',
					$this->mailbox['ID'],
					$this->mailbox['SYNC_LOCK']
				));
				$this->mailbox['SYNC_LOCK'] = 0;
			}

			throw $exception;
		}

		// The first steps may outlive the lease: another pass that took the expired lock owns the mailbox now
		if (!$this->mailboxLockHeldByCaller && !$this->renewSyncLock())
		{
			$this->lastSyncRefusal = static::SYNC_REFUSAL_DB_LOCK_LOST;

			return 0;
		}

		$mailboxSyncManager = new Mailbox\MailboxSyncManager($this->mailbox['USER_ID']);
		if ($this->mailbox['USER_ID'] > 0)
		{
			$mailboxSyncManager->setSyncStartedData($this->mailbox['ID']);
		}

		$syncReport = $this->syncInternal();
		$count = $syncReport['syncCount'];

		if($syncReport['reSyncStatus'])
		{
			/*
			When folders are successfully resynchronized,
			allow messages that were left to be moved to be deleted
			*/
			MailMessageUidTable::updateList(
				$this->getGenerationScope()->apply([
					'=MAILBOX_ID' => $this->mailbox['ID'],
					'=MSG_UID' => 0,
					'=IS_OLD' => 'M',
				]),
				[
					'IS_OLD' => 'R',
				],
			);
		}

		$success = $count !== false && $this->errors->isEmpty();

		$syncUnlock = $this->isTimeQuotaExceeded() ? 0 : -1;

		$interval = max(1, (int) $this->mailbox['PERIOD_CHECK']) * 60;
		$syncErrors = max(0, (int) $this->mailbox['OPTIONS']['sync_errors']);

		if ($count === false)
		{
			$syncErrors++;

			$maxInterval = 3600 * 24 * 7;
			for ($i = 1; $i < $syncErrors && $interval < $maxInterval; $i++)
			{
				$interval = min($interval * ($i + 1), $maxInterval);
			}
		}
		else
		{
			$syncErrors = 0;

			$interval = $syncUnlock < 0 ? $interval : min($count > 0 ? 60 : 600, $interval);
		}

		$this->mailbox['OPTIONS']['sync_errors'] = $syncErrors;
		$this->mailbox['OPTIONS']['next_sync'] = time() + $interval;

		$optionsValue = $this->mailbox['OPTIONS'];

		if ($this->mailboxLockHeldByCaller)
		{
			// The lock belongs to the caller until it gives it back: only the options are stored
			$DB->query(sprintf(
				"UPDATE b_mail_mailbox SET OPTIONS = '%s' WHERE ID = %u",
				$DB->forSql(serialize($optionsValue)),
				$this->mailbox['ID']
			));

			$this->mailbox['SYNC_LOCK'] = $syncUnlock;
		}
		else
		{
			$unlockSql = sprintf(
				"UPDATE b_mail_mailbox SET SYNC_LOCK = %d, OPTIONS = '%s' WHERE ID = %u AND SYNC_LOCK = %u",
				$syncUnlock,
				$DB->forSql(serialize($optionsValue)),
				$this->mailbox['ID'],
				$this->mailbox['SYNC_LOCK']
			);
			if ($DB->query($unlockSql)->affectedRowsCount())
			{
				$this->mailbox['SYNC_LOCK'] = $syncUnlock;
			}
		}

		$lastSyncResult = $this->getLastSyncResult();

		$this->pushSyncStatus(
			array(
				'new' => $count,
				'updated' => $lastSyncResult['updatedMessages'],
				'deleted' => $lastSyncResult['deletedMessages'],
				'complete' => $this->mailbox['SYNC_LOCK'] < 0,
			),
			true
		);

		$this->notifyNewMessages();

		if ($this->mailbox['USER_ID'] > 0)
		{
			$mailboxSyncManager->setSyncStatus($this->mailbox['ID'], $success, time());
		}

		if($syncCounters)
		{
			$this->syncCounters();
		}

		return $count;
	}

	protected function ensureCrmImapGateReady(): Main\Result
	{
		return (new CrmImapGateReconciler())->ensureReady((int)$this->mailbox['ID']);
	}

	protected function registerCrmImapGateErrors(Main\Result $result): void
	{
		$this->errors->add($result->getErrors());
		$this->warnings->add($result->getErrors());
	}

	public function getSyncStatus()
	{
		return -1;
	}

	protected function pushSyncStatus($params, $force = false)
	{
		if (Loader::includeModule('pull'))
		{
			$status = $this->getSyncStatus();

			\CPullWatch::addToStack(
				'mail_mailbox_' . $this->mailbox['ID'],
				array(
					'module_id' => 'mail',
					'command' => 'mailbox_sync_status',
					'params' => array_merge(
						array(
							'id' => $this->mailbox['ID'],
							'status' => sprintf('%.3f', $status),
							'sessid' => $this->syncParams['sessid'] ?? $this->session,
							'timestamp' => microtime(true),
						),
						$params
					),
				)
			);

			if ($force)
			{
				\Bitrix\Pull\Event::send();
			}
		}
	}

	public function dismissOldMessages()
	{
		global $DB;

		if (!Mail\Helper\LicenseManager::isCleanupOldEnabled())
		{
			return true;
		}

		// Zeroing MESSAGE_ID is a full mutation: only the active generation accepts it
		if (!$this->checkSyncGenerationContext())
		{
			return false;
		}

		$startTime = time();

		if (time() - $this->mailbox['SYNC_LOCK'] < static::getTimeout())
		{
			return false;
		}

		if ($this->isTimeQuotaExceeded())
		{
			return false;
		}

		$syncUnlock = $this->mailbox['SYNC_LOCK'];

		$lockSql = sprintf(
			'UPDATE b_mail_mailbox SET SYNC_LOCK = %u WHERE ID = %u AND (SYNC_LOCK IS NULL OR SYNC_LOCK < %u)',
			$startTime, $this->mailbox['ID'], $startTime - static::getTimeout()
		);
		if ($DB->query($lockSql)->affectedRowsCount())
		{
			$this->mailbox['SYNC_LOCK'] = $startTime;
		}
		else
		{
			return false;
		}

		$result = true;

		$entity = MailMessageUidTable::getEntity();
		$connection = $entity->getConnection();

		$scope = $this->getGenerationScope();

		$whereConditionForOldMessages = sprintf(
			' (%s)',
			ORM\Query\Query::buildFilterSql(
				$entity,
				$scope->apply(array(
					'=MAILBOX_ID' => $this->mailbox['ID'],
					'>MESSAGE_ID' => 0,
					'<INTERNALDATE' => Main\Type\Date::createFromTimestamp(Mailbox\SyncPeriodBoundary::dayStartUtcMinusDays(Mail\Helper\LicenseManager::getSyncOldLimit())),
					'!=IS_OLD' => 'Y',
				))
			)
		);

		$sqlHelper = $connection->getSqlHelper();

		while (true)
		{
			$oldMessages = \Bitrix\Mail\MailMessageUidTable::query()
				->setSelect([
					'ID',
					'MAILBOX_ID',
					'MESSAGE_ID',
					'GENERATION_ID',
				])
				->setFilter($scope->apply([
					'=MAILBOX_ID' => $this->mailbox['ID'],
					'>MESSAGE_ID' => 0,
					'<INTERNALDATE' => \Bitrix\Main\Type\Date::createFromTimestamp(Mailbox\SyncPeriodBoundary::dayStartUtcMinusDays(\Bitrix\Mail\Helper\LicenseManager::getSyncOldLimit())),
				]))
				->whereNotExists(
					new \Bitrix\Main\DB\SqlExpression("
						SELECT 1
						FROM " . \Bitrix\Mail\Internals\MessageAccessTable::getTableName() . "
						WHERE
							MAILBOX_ID = " . \Bitrix\Mail\MailMessageUidTable::query()->getInitAlias() . ".MAILBOX_ID
							AND MESSAGE_ID = " . \Bitrix\Mail\MailMessageUidTable::query()->getInitAlias() . ".MESSAGE_ID
							AND ENTITY_TYPE IN ('" . \Bitrix\Mail\Internals\MessageAccessTable::ENTITY_TYPE_TASKS_TASK . "','" . \Bitrix\Mail\Internals\MessageAccessTable::ENTITY_TYPE_BLOG_POST . "')"
					)
				)->setLimit(static::MESSAGE_DELETION_LIMIT_AT_A_TIME)->exec()
			;

			$messageAsStringForSql = [];
			$oldMessageIds = [];

			while ($oldMessage = $oldMessages->fetch())
			{
				$id = $oldMessage['ID'];
				$oldMessageIds[] = $id;

				[, $insert] = $sqlHelper->prepareInsert(\Bitrix\Mail\Internals\MessageDeleteQueueTable::getTableName(),
					[
						'ID' => $id,
						'MAILBOX_ID' => (int)$oldMessage['MAILBOX_ID'],
						'MESSAGE_ID' => (int)$oldMessage['MESSAGE_ID'],
						// The queue row inherits the generation of the uid row it replaces
						'GENERATION_ID' => (int)$oldMessage['GENERATION_ID'],
					]
				);

				$messageAsStringForSql[] = "($insert)";
			}

			if (empty($oldMessageIds))
			{
				break;
			}

			\Bitrix\Mail\MailMessageUidTable::updateList(
				$scope->apply([
					'!=MESSAGE_ID' => 0,
					'=MAILBOX_ID' => $this->mailbox['ID'],
					'@ID' => $oldMessageIds,
				]),
				[
					'MESSAGE_ID' => 0,
				],
				sendEvent: false,
			);

			$connection->queryExecute(
				$sqlHelper->getInsertIgnore(
					\Bitrix\Mail\Internals\MessageDeleteQueueTable::getTableName(),
					'(ID, MAILBOX_ID, MESSAGE_ID, GENERATION_ID)',
					'VALUES ' . implode(', ', $messageAsStringForSql)
				)
			);

			if ($this->isTimeQuotaExceeded() || time() - $this->checkpoint > 15)
			{
				$result = false;

				break;
			}
		}

		if ($result !== false)
		{
			do
			{
				$connection->query(sprintf(
					"UPDATE %s SET IS_OLD = 'Y', IS_SEEN = 'Y' WHERE %s ORDER BY ID LIMIT " . static::MESSAGE_SET_OLD_STATUS_LIMIT_AT_A_TIME,
					$connection->getSqlHelper()->quote($entity->getDbTableName()),
					$whereConditionForOldMessages
				));

				if ($this->isTimeQuotaExceeded() || time() - $this->checkpoint > 15)
				{
					$result = false;

					break;
				}

			}
			while ($connection->getAffectedRowsCount() >= static::MESSAGE_SET_OLD_STATUS_LIMIT_AT_A_TIME);
		}

		$unlockSql = sprintf(
			"UPDATE b_mail_mailbox SET SYNC_LOCK = %d WHERE ID = %u AND SYNC_LOCK = %u",
			$syncUnlock, $this->mailbox['ID'], $this->mailbox['SYNC_LOCK']
		);
		if ($DB->query($unlockSql)->affectedRowsCount())
		{
			$this->mailbox['SYNC_LOCK'] = $syncUnlock;
		}

		return $result;
	}

	/**
	 * Drops the rows the sync has already seen as gone from the physical source.
	 * Belongs to the regular cleanup, so a prepared generation never participates.
	 *
	 * @return bool False when the pass is not over: the rows left beyond the deletion limit
	 *              are for the next visit of the cleanup agent.
	 */
	public function dismissRemoteMessages(): bool
	{
		if (!$this->checkSyncGenerationContext())
		{
			return false;
		}

		$filter = $this->getGenerationScope()->apply([
			'=MAILBOX_ID' => $this->mailbox['ID'],
			'!=MESSAGE_ID' => 0,
			'=IS_OLD' => MailMessageUidTable::REMOTE,
		]);

		/*
			A mailbox emptied on the server can hold any number of rows, so regular cleanup
			removes its remote placements in limited passes.
		*/
		$deleted = MailMessageUidTable::deleteList(
			$filter,
			[],
			static::MESSAGE_DELETION_LIMIT_AT_A_TIME,
			sendEvent: false,
		);

		if (!$deleted)
		{
			return true;
		}

		// Asked after a deletion only: that is where the limit may have cut the pass short
		$remaining = MailMessageUidTable::query()
			->setSelect(['ID'])
			->setFilter($filter)
			->setLimit(1)
			->fetch()
		;

		return empty($remaining);
	}

	public function dismissDeletedUidMessages()
	{
		global $DB;

		if (!$this->checkSyncGenerationContext())
		{
			return false;
		}

		$startTime = time();

		if (time() - $this->mailbox['SYNC_LOCK'] < static::getTimeout())
		{
			return false;
		}

		if ($this->isTimeQuotaExceeded())
		{
			return false;
		}

		$syncUnlock = $this->mailbox['SYNC_LOCK'];

		$lockSql = sprintf(
			'UPDATE b_mail_mailbox SET SYNC_LOCK = %u WHERE ID = %u AND (SYNC_LOCK IS NULL OR SYNC_LOCK < %u)',
			$startTime, $this->mailbox['ID'], $startTime - static::getTimeout()
		);
		if ($DB->query($lockSql)->affectedRowsCount())
		{
			$this->mailbox['SYNC_LOCK'] = $startTime;
		}
		else
		{
			return false;
		}

		$scope = $this->getGenerationScope();
		$minSyncTime = Mail\MailboxDirectory::getMinSyncTime($this->mailbox['ID'], $scope);

		MailMessageUidTable::deleteList(
			$scope->apply([
				'=MAILBOX_ID'  => $this->mailbox['ID'],
				'!=MESSAGE_ID' => 0,
				'>DELETE_TIME' => 0,
				/*The values in the tables are still used to delete related items (example: attachments):*/
				'<DELETE_TIME' => $minSyncTime,
			]),
			[],
			static::MESSAGE_DELETION_LIMIT_AT_A_TIME
		);

		$unlockSql = sprintf(
			"UPDATE b_mail_mailbox SET SYNC_LOCK = %d WHERE ID = %u AND SYNC_LOCK = %u",
			$syncUnlock, $this->mailbox['ID'], $this->mailbox['SYNC_LOCK']
		);
		if ($DB->query($unlockSql)->affectedRowsCount())
		{
			$this->mailbox['SYNC_LOCK'] = $syncUnlock;
		}

		return true;
	}

	public function cleanup()
	{
		if (!$this->checkSyncGenerationContext())
		{
			return false;
		}

		$scope = $this->getGenerationScope();
		$conversationBackfill = new ConversationBackfill();

		do
		{
			$res = Mail\Internals\MessageDeleteQueueTable::getList(array(
				'runtime' => array(
					/*
						Deliberately not generation-scoped: a logical message survives while any
						retained generation still holds a placement of it.
					*/
					new ORM\Fields\Relations\Reference(
						'MESSAGE_UID',
						'Bitrix\Mail\MailMessageUidTable',
						array(
							'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
							'=this.MESSAGE_ID' => 'ref.MESSAGE_ID',
						)
					),
				),
				'select' => array('MESSAGE_ID', 'UID' => 'MESSAGE_UID.ID'),
				'filter' => $scope->apply(array(
					'=MAILBOX_ID' => $this->mailbox['ID'],
				)),
				'limit' => 100,
			));

			$count = 0;
			while ($item = $res->fetch())
			{
				$count++;

				if (empty($item['UID']))
				{
					\CMailMessage::delete($item['MESSAGE_ID'], (int)$this->mailbox['ID']);
					$conversationBackfill->removeMessages(
						(int)$this->mailbox['ID'],
						[(int)$item['MESSAGE_ID']],
					);
				}

				Mail\Internals\MessageDeleteQueueTable::deleteList($scope->apply(array(
					'=MAILBOX_ID' => $this->mailbox['ID'],
					'=MESSAGE_ID' => $item['MESSAGE_ID'],
				)));

				if ($this->isTimeQuotaExceeded() || time() - $this->checkpoint > 60)
				{
					return false;
				}
			}
		}
		while ($count > 0);

		return true;
	}

	/**
	 * The rows of the mailbox this run works with. The callers ask by physical coordinates - dir hash,
	 * UIDVALIDITY, uid - and those belong to the source generation that issued them, so the generation
	 * scope of the run is part of the filter by default. Without it a row of a retained generation
	 * answers for the uid the active source is being asked about and its letter never arrives.
	 *
	 * @param bool $withGenerationScope The declared way out for the one caller that does not mean the
	 *        generation of the run: the lookup of the logical identity of a letter by its header hash,
	 *        which a letter keeps across a change of the source of the mailbox. That caller carries a
	 *        scope of its own {@see Imap::searchExistingMessagesByHeaderInDataBase()}.
	 */
	protected function listMessages($params = array(), $fetch = true, bool $withGenerationScope = true)
	{
		$filter = array(
			'=MAILBOX_ID' => $this->mailbox['ID'],
		);

		if (!empty($params['filter']))
		{
			$filter = array_merge((array) $params['filter'], $filter);
		}

		$params['filter'] = $withGenerationScope ? $this->peekGenerationScope()->apply($filter) : $filter;

		$result = MailMessageUidTable::getList($params);

		return $fetch ? $result->fetchAll() : $result;
	}

	protected function findMessageInUploadQueue(
		$idFromHeaderMessage,
	): Result
	{
		return MessageUploadQueueTable::getList([
			'select' => [
				'ID',
				'MESSAGE_ID' => 'UID_TABLE.MESSAGE_ID',
			],
			'filter'=> $this->getGenerationScope()->apply([
				'=SYNC_STAGE' => -1,
				'=SYNC_LOCK' => 0,
				'=MAILBOX_ID'=> $this->mailbox['ID'],
				'=UID_TABLE.IS_OLD' => MailMessageUidTable::DOWNLOADED,
				'=UID_TABLE.DELETE_TIME' => 0,
				'=UID_TABLE.MESSAGE_TABLE.MSG_ID' => $idFromHeaderMessage,
			]),
			'limit' => 1,
		]);
	}

	protected function registerMessage(&$fields, $replaces = null, $isOutgoing = false, string $idFromHeaderMessage = '', $redefineInsertDate = true, string $messageStatus = \Bitrix\Mail\MailMessageUidTable::DOWNLOADED): bool
	{
		$now = new Main\Type\DateTime();

		$replacingMessageFromQueue = false;

		if (!empty($replaces))
		{
			/*
				To replace the temporary id of outgoing emails with a permanent one
				after receiving the uid from the original mail service.
			*/
			if($isOutgoing)
			{
				if (!is_array($replaces))
				{
					$replaces = [
						'=ID' => $replaces,
					];
				}

				$exists = MailMessageUidTable::getList([
					'select' => [
						'ID',
						'MESSAGE_ID',
					],
					'filter' => [
						$replaces,
						'=MAILBOX_ID' => $this->mailbox['ID'],
						'==DELETE_TIME' => 0,
					],
				])->fetch();
			}
			else
			{
				$exists = [
					'ID' => $replaces,
					'MESSAGE_ID' => $fields['MESSAGE_ID'],
				];
			}
		}
		else if ($isOutgoing && $idFromHeaderMessage !== '')
		{
			/*
			 * Find and link an message if the unloading of outgoing emails to the "Sent" folder
			 * on the service is disabled and the service itself created the email in this folder.
			 */
			$exists = $this->findMessageInUploadQueue(
				$idFromHeaderMessage,
			)->fetch();

			$replacingMessageFromQueue = true;
		}

		if (!empty($exists))
		{
			$fields['MESSAGE_ID'] = $exists['MESSAGE_ID'];

			$result = (bool) MailMessageUidTable::updateList(
				array(
					'=ID' => $exists['ID'],
					'=MAILBOX_ID' => $this->mailbox['ID'],
					'==DELETE_TIME' => 0,
				),
				array_merge(
					$fields,
					array(
						'TIMESTAMP_X' => $now,
					)
				),
				array_merge(
					$exists,
					array(
						'MAILBOX_USER_ID' => $this->mailbox['USER_ID'],
					)
				)
			);

			if ($replacingMessageFromQueue && $result)
			{
				Mail\Internals\MessageUploadQueueTable::delete(array(
					'ID' => $exists['ID'],
					'MAILBOX_ID' => (int) $this->mailbox['ID'],
				));
			}
		}
		else
		{
			$checkResult = new ORM\Data\AddResult();
			$addFields = array_merge(
				[
					'MESSAGE_ID'  => 0,
				],
				$fields,
				[
					'IS_OLD' => $messageStatus,
					'MAILBOX_ID'  => $this->mailbox['ID'],
					// New physical rows always belong to the generation of the current context
					'GENERATION_ID' => $this->getGenerationContext()->generationId,
					'SESSION_ID'  => $this->session,
					'TIMESTAMP_X' => $now,
				]
			);

			if ($redefineInsertDate || !array_key_exists('DATE_INSERT', $fields))
			{
				$addFields['DATE_INSERT'] = $now;
			}

			MailMessageUidTable::checkFields($checkResult, null, $addFields);
			if (!$checkResult->isSuccess())
			{
				return false;
			}

			MailMessageUidTable::mergeData($addFields, [
				'MSG_UID' => $addFields['MSG_UID'],
				'HEADER_MD5' => $addFields['HEADER_MD5'],
				'SESSION_ID' => $addFields['SESSION_ID'],
				'TIMESTAMP_X' => $addFields['TIMESTAMP_X'],
				// the row is observed alive on the server again, so the soft deletion mark is dropped
				'DELETE_TIME' => 0,
			]);

			return true;
		}

		return $result;
	}

	protected function updateMessagesRegistry(array $filter, array $fields, $mailData = array())
	{
		return MailMessageUidTable::updateList(
			$this->getGenerationScope()->apply(array_merge(
				$filter,
				array(
					'!=IS_OLD' => 'Y',
					'=MAILBOX_ID' => $this->mailbox['ID'],
				)
			)),
			$fields,
			$mailData
		);
	}

	/**
	 * @param bool $keptAliveRows - reports back whether the guard kept any row of the sample from being
	 *        deleted. A caller that has to come back for the rest of the batch reads it; the answer of the
	 *        method stays the count of the deleted rows for everyone else, and nothing left to delete is
	 *        answered with nothing at all - a count of zero to every caller of the two.
	 */
	protected function unregisterMessages(
		$filter,
		$eventData = [],
		$ignoreDeletionCheck = false,
		bool &$keptAliveRows = false,
	)
	{
		$messagesForRemove = [];
		$aliveMessages = [];
		$unansweredMessages = [];
		$filterForCheck = [];
		$scope = $this->getGenerationScope();
		$keptAliveRows = false;

		if(!$ignoreDeletionCheck)
		{
			$filterForCheck = $scope->apply(array_merge(
				$filter,
				MailMessageUidTable::getPresetRemoveFilters(),
				[
					'=MAILBOX_ID' => $this->mailbox['ID'],
					/*
						We check illegally deleted messages,
						the disappearance of which the user may notice.
						According to such data, it is easier to find a message
						in the original mailbox for diagnostics.
					*/
					'!=MESSAGE_ID'  => 0,
				]
			));

			$messagesForRemove = $this->listMessages([
				'select' => [
					'ID',
					'MAILBOX_ID',
					'DIR_MD5',
					'DIR_UIDV',
					'MSG_UID',
					'INTERNALDATE',
					'IS_SEEN',
					'DATE_INSERT',
					'MESSAGE_ID',
					'IS_OLD',
				],
				'filter' => $filterForCheck,
				'limit' => 100,
			]);

			$aliveMessages = $this->selectMessagesAliveInTheOriginalMailbox($messagesForRemove, $unansweredMessages);
		}

		if (empty($aliveMessages))
		{
			return $this->deleteMessagesRegistry($filter);
		}

		$keptAliveRows = true;

		/*
			The mail server answers for a part of the sample, so the filter is no longer taken at its word:
			only the rows it reached and the server disowned are removed. The rest of what the filter covers -
			the messages beyond the sample among them - waits for a pass of its own, where it will be asked
			about in its turn.
		*/
		$deadIds = array_diff(
			array_column($messagesForRemove, 'ID'),
			array_column($aliveMessages, 'ID'),
			array_column($unansweredMessages, 'ID'),
		);

		if (empty($deadIds))
		{
			return null;
		}

		return $this->deleteMessagesRegistry(
			array_merge(
				$filter,
				[
					'@ID' => array_values($deadIds),
				]
			)
		);
	}

	/**
	 * Rows of the sample whose messages are still in the mailbox on the mail server. Only the folder of
	 * the first row is asked about, so the answer covers the sample as far as it lies in that folder.
	 *
	 * @param array $unansweredMessages - reports back the rows the mail server was asked about and said
	 *        nothing of: a request of theirs ended with an error, and an error tells a deletion from a
	 *        message the request never reached no better than silence does.
	 */
	private function selectMessagesAliveInTheOriginalMailbox(
		array $messagesForRemove,
		array &$unansweredMessages = [],
	): array
	{
		$unansweredMessages = [];

		if (empty($messagesForRemove) || !isset($messagesForRemove[0]['DIR_MD5']))
		{
			return [];
		}

		$dirPath = $this->getDirsHelper()->getDirPathByHash($messagesForRemove[0]['DIR_MD5']);

		$messagesForRemove = $this->placementsTheSourceCanBeAskedAbout($messagesForRemove, (string)$dirPath);

		if (empty($messagesForRemove))
		{
			return [];
		}

		// an implementation of the check that knows nothing of failed requests leaves the list empty
		$this->unansweredUidsOfLastCheck = [];

		$aliveUids = $this->checkMessagesForExistence(
			$dirPath,
			array_column($messagesForRemove, 'MSG_UID')
		);

		// an implementation written before the list contract answers with a single uid or with false
		if (!is_array($aliveUids))
		{
			$aliveUids = empty($aliveUids) ? [] : [$aliveUids];
		}

		$unansweredMessages = self::selectMessagesByUids($messagesForRemove, $this->unansweredUidsOfLastCheck);

		return self::selectMessagesByUids($messagesForRemove, $aliveUids);
	}

	/**
	 * Of the rows the deletion is about to ask the source about, the ones it can still be asked about at
	 * all. Answered by the engine, which alone knows what its source is; the base answers with all of them.
	 */
	protected function placementsTheSourceCanBeAskedAbout(array $messagesForRemove, string $dirPath): array
	{
		return $messagesForRemove;
	}

	/** @return array rows of the sample whose uid the list names */
	private static function selectMessagesByUids(array $messagesForRemove, array $uids): array
	{
		$uids = array_map('intval', $uids);

		return array_values(
			array_filter(
				$messagesForRemove,
				static function ($message) use ($uids)
				{
					return isset($message['MSG_UID']) && in_array((int)$message['MSG_UID'], $uids, true);
				}
			)
		);
	}

	/**
	 * The scope lives here and not in the callers: the deletion of the registry answers to a filter built
	 * by the run, and a row of a retained generation names a physical source the mailbox has left - the
	 * run has no business judging it, whatever the filter happens to cover.
	 */
	protected function deleteMessagesRegistry(array $filter)
	{
		return MailMessageUidTable::deleteListSoft(
			$this->getGenerationScope()->apply(
				array_merge(
					$filter,
					[
						'=MAILBOX_ID' => $this->mailbox['ID'],
					]
				)
			)
		);
	}

	protected function linkMessage($uid, $id)
	{
		$result = MailMessageUidTable::update(
			array(
				'ID' => $uid,
				'MAILBOX_ID' => $this->mailbox['ID'],
			),
			array(
				'MESSAGE_ID' => $id,
			)
		);

		return $result->isSuccess();
	}

	protected function cacheMessage(&$body, $params = array())
	{
		if (empty($params['origin']) && empty($params['replaces']))
		{
			$params['lazy_attachments'] = $this->isSupportLazyAttachments();
		}
		$params[MailMessageTable::FIELD_SANITIZE_ON_VIEW] ??= $this->isSupportSanitizeOnView();

		$messageId = \CMailMessage::addMessage(
			$this->mailbox['ID'],
			$body,
			$this->mailbox['CHARSET'] ?: $this->mailbox['LANG_CHARSET'],
			$params
		);

		/*
			The single funnel of every save of a letter, so the conversation projection is brought up to
			date right here: the closure of the letter is written inside addMessage above, and the pull
			command that makes the client reload its list is sent later, after syncMessage() returns.
			No transaction of the synchronization is open at this point, which is what the projection
			requires - see the contract of ConversationIndexer.
		*/
		Helper\Message::syncConversationProjection((int)$this->mailbox['ID'], (int)$messageId);

		return $messageId;
	}

	/**
	 * @param int $authorId Who is told when the letter cannot be sent; without one the refusal is only shown
	 * on the screen of the letter.
	 */
	public function mail(array $params, ?callable $onQueued = null, int $authorId = 0)
	{
		class_exists('Bitrix\Mail\Helper');

		$message = new Mail\DummyMail($params);

		$messageUid = $this->createMessage($message, [], $authorId);

		if ($onQueued === null)
		{
			Mail\Internals\MessageUploadQueueTable::add(array(
				'ID' => $messageUid,
				'MAILBOX_ID' => $this->mailbox['ID'],
				'GENERATION_ID' => $this->getGenerationContext()->generationId,
			));
		}
		else
		{
			$connection = Main\Application::getConnection();
			$connection->startTransaction();
			try
			{
				$queueResult = Mail\Internals\MessageUploadQueueTable::add(array(
					'ID' => $messageUid,
					'MAILBOX_ID' => $this->mailbox['ID'],
					'GENERATION_ID' => $this->getGenerationContext()->generationId,
				));
				if (!$queueResult->isSuccess())
				{
					throw new Main\SystemException('Could not queue outgoing message.');
				}

				$onQueued();
				$connection->commitTransaction();
			}
			catch (\Throwable $exception)
			{
				try
				{
					$connection->rollbackTransaction();
				}
				catch (Main\DB\TransactionException)
				{
					// Nested rollback has already restored the savepoint.
				}

				throw $exception;
			}
		}

		\CAgent::addAgent(
			sprintf(
				'Bitrix\Mail\Helper::syncOutgoingAgent(%u);',
				$this->mailbox['ID']
			),
			'mail', 'N', 60
		);
	}

	protected function createMessage(Main\Mail\Mail $message, array $fields = array(), int $authorId = 0)
	{
		$messageUid = sprintf('%x%x', time(), rand(0, 0xffffffff));
		$body = sprintf(
			'%1$s%3$s%3$s%2$s',
			$message->getHeaders(),
			$message->getBody(),
			$message->getMailEol()
		);

		$messageId = $this->cacheMessage(
			$body,
			array(
				'outcome' => true,
				'draft' => false,
				'trash' => false,
				'spam' => false,
				'seen' => true,
				'trackable' => true,
				'origin' => true,
				'outgoing_author_id' => $authorId,
			)
		);

		$fields = array_merge(
			$fields,
			array(
				'ID' => $messageUid,
				'INTERNALDATE' => new Main\Type\DateTime,
				'IS_SEEN' => 'Y',
				'MESSAGE_ID' => $messageId,
			)
		);

		$this->registerMessage($fields);

		return $messageUid;
	}

	public function syncOutgoing()
	{
		$res = $this->listMessages(
			array(
				'runtime' => array(
					new \Bitrix\Main\Entity\ReferenceField(
						'UPLOAD_QUEUE',
						'Bitrix\Mail\Internals\MessageUploadQueueTable',
						array(
							'=this.ID' => 'ref.ID',
							'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						),
						array(
							'join_type' => 'INNER',
						)
					),
				),
				'select' => array(
					'*',
					'__' => 'MESSAGE.*',
					'UPLOAD_LOCK' => 'UPLOAD_QUEUE.SYNC_LOCK',
					'UPLOAD_STAGE' => 'UPLOAD_QUEUE.SYNC_STAGE',
					'UPLOAD_ATTEMPTS' => 'UPLOAD_QUEUE.ATTEMPTS',
				),
				'filter' => $this->peekGenerationScope()->apply(array(
					'>=UPLOAD_QUEUE.SYNC_STAGE' => 0,
					'<UPLOAD_QUEUE.SYNC_LOCK' => time() - static::getTimeout(),
					'<UPLOAD_QUEUE.ATTEMPTS' => self::MAX_OUTGOING_ATTEMPTS,
				)),
				'order' => array(
					'UPLOAD_QUEUE.SYNC_LOCK' => 'ASC',
					'UPLOAD_QUEUE.SYNC_STAGE' => 'ASC',
					'UPLOAD_QUEUE.ATTEMPTS' => 'ASC',
				),
			),
			false
		);

		while ($excerpt = $res->fetch())
		{
			$n = $excerpt['UPLOAD_ATTEMPTS'] + 1;
			$interval = min(static::getTimeout() * pow($n, $n), 3600 * 24 * 7);

			if ($excerpt['UPLOAD_LOCK'] > time() - $interval)
			{
				continue;
			}

			$this->syncOutgoingMessage($excerpt);

			if ($this->isTimeQuotaExceeded())
			{
				break;
			}
		}
	}

	protected function syncOutgoingMessage($excerpt)
	{
		global $DB;

		$lockSql = sprintf(
			"UPDATE b_mail_message_upload_queue SET SYNC_LOCK = %u, SYNC_STAGE = %u, ATTEMPTS = ATTEMPTS + 1
				WHERE ID = '%s' AND MAILBOX_ID = %u AND SYNC_LOCK < %u",
			$syncLock = time(),
			max(1, $excerpt['UPLOAD_STAGE']),
			$DB->forSql($excerpt['ID']),
			$excerpt['MAILBOX_ID'],
			$syncLock - static::getTimeout()
		);
		if (!$DB->query($lockSql)->affectedRowsCount())
		{
			return;
		}

		$outgoingBody = $excerpt['__BODY_HTML'];

		$excerpt['__files'] = Mail\Internals\MailMessageAttachmentTable::getList(array(
			'select' => array(
				'ID', 'FILE_ID', 'FILE_NAME',
			),
			'filter' => array(
				'=MESSAGE_ID' => $excerpt['__ID'],
				'=EXTERNAL_LINK_ID' => null,
			),
		))->fetchAll();

		$attachments = array();
		if (!empty($excerpt['__files']) && is_array($excerpt['__files']))
		{
			$hostname = \COption::getOptionString('main', 'server_name', 'localhost');
			if (defined('BX24_HOST_NAME') && BX24_HOST_NAME != '')
			{
				$hostname = BX24_HOST_NAME;
			}
			else if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME != '')
			{
				$hostname = SITE_SERVER_NAME;
			}

			foreach ($excerpt['__files'] as $item)
			{
				$file = \CFile::makeFileArray($item['FILE_ID']);

				$contentId = sprintf(
					'bxacid.%s@%s.mail',
					hash('crc32b', $file['external_id'].$file['size'].$file['name']),
					hash('crc32b', $hostname)
				);

				$attachments[] = array(
					'ID'           => $contentId,
					'NAME'         => $item['FILE_NAME'],
					'PATH'         => $file['tmp_name'],
					'CONTENT_TYPE' => $file['type'],
				);

				$outgoingBody = preg_replace(
					sprintf('/aid:%u/i', $item['ID']),
					sprintf('cid:%s', $contentId),
					$outgoingBody
				);
			}
		}

		foreach (array('FROM', 'REPLY_TO', 'TO', 'CC', 'BCC') as $field)
		{
			$field = sprintf('__FIELD_%s', $field);

			if (mb_strlen($excerpt[$field]) == 255 && '' != $excerpt['__HEADER'] && empty($parsedHeader))
			{
				$parsedHeader = \CMailMessage::parseHeader($excerpt['__HEADER'], LANG_CHARSET);

				$excerpt['__FIELD_FROM'] = $parsedHeader->getHeader('FROM');
				$excerpt['__FIELD_REPLY_TO'] = $parsedHeader->getHeader('REPLY-TO');
				$excerpt['__FIELD_TO'] = $parsedHeader->getHeader('TO');
				$excerpt['__FIELD_CC'] = $parsedHeader->getHeader('CC');
				$excerpt['__FIELD_BCC'] = Message::getBccFromParsedHeader($parsedHeader);
			}

			$excerpt[$field] = explode(',', $excerpt[$field]);

			foreach ($excerpt[$field] as $k => $item)
			{
				unset($excerpt[$field][$k]);

				$address = new Main\Mail\Address($item);

				if ($address->validate())
				{
					if ($address->getName())
					{
						$excerpt[$field][] = sprintf(
							'%s <%s>',
							sprintf('=?%s?B?%s?=', SITE_CHARSET, base64_encode($address->getName())),
							$address->getEmail()
						);
					}
					else
					{
						$excerpt[$field][] = $address->getEmail();
					}
				}
			}

			$excerpt[$field] = join(', ', $excerpt[$field]);
		}

		$outgoingParams = [
			'CHARSET'      => LANG_CHARSET,
			'CONTENT_TYPE' => 'html',
			'ATTACHMENT'   => $attachments,
			'TO'           => $excerpt['__FIELD_TO'],
			'SUBJECT'      => $excerpt['__SUBJECT'],
			'BODY'         => $outgoingBody,
			'HEADER'       => [
				'From'       => $excerpt['__FIELD_FROM'],
				'Reply-To'   => $excerpt['__FIELD_REPLY_TO'],
				'Cc'         => $excerpt['__FIELD_CC'],
				'Bcc'        => $excerpt['__FIELD_BCC'],
				'Message-Id' => sprintf('<%s>', $excerpt['__MSG_ID']),
			],
		];

		if (Option::get('mail', 'embed_local_id_in_outgoing_message_header', 'Y') == 'Y')
		{
			$outgoingParams['HEADER']['X-Bitrix-Mail-Message-UID'] = $excerpt['ID'];
		}

		if(isset($excerpt['__IN_REPLY_TO']))
		{
			$outgoingParams['HEADER']['In-Reply-To'] = sprintf('<%s>', $excerpt['__IN_REPLY_TO']);
		}

		$context = new Main\Mail\Context();
		$context->setCategory(Main\Mail\Context::CAT_EXTERNAL);
		$context->setPriority(Main\Mail\Context::PRIORITY_NORMAL);
		$this->applySenderIdentity($context, (int) $excerpt['MAILBOX_ID']);

		$eventManager = \Bitrix\Main\EventManager::getInstance();
		$eventKey = $eventManager->addEventHandler(
			'main',
			'OnBeforeMailSend',
			function () use (&$excerpt)
			{
				if ($excerpt['UPLOAD_STAGE'] >= 2)
				{
					return new Main\EventResult(Main\EventResult::ERROR);
				}
			}
		);

		$mailParams = array_merge(
			$outgoingParams,
			array(
				'TRACK_READ' => array(
					'MODULE_ID' => 'mail',
					'FIELDS'    => array('msgid' => $excerpt['__MSG_ID']),
					'URL_PAGE' => '/pub/mail/read.php',
				),
				//'TRACK_CLICK' => array(
				//	'MODULE_ID' => 'mail',
				//	'FIELDS'    => array('msgid' => $excerpt['__MSG_ID']),
				//),
				'CONTEXT' => $context,
			)
		);
		$sendResult = null;
		if (method_exists(Main\Mail\Mail::class, 'sendResult'))
		{
			$sendResult = Main\Mail\Mail::sendResult($mailParams);
			$success = $sendResult->isSuccess();
		}
		else
		{
			$success = Main\Mail\Mail::send($mailParams);
		}

		$eventManager->removeEventHandler('main', 'OnBeforeMailSend', $eventKey);

		if ($excerpt['UPLOAD_STAGE'] < 2 && !$success)
		{
			$sendingError = $this->resolvePermanentSendError($excerpt, $context, $sendResult);
			if ($sendingError !== null)
			{
				$this->completeOutgoingWithError($excerpt, $sendingError);
			}

			return false;
		}

		$needUpload = true;
		if ($context->getSmtp() && $context->getSmtp()->getFrom() == $this->mailbox['EMAIL'])
		{
			$needUpload = !in_array('deny_upload', (array) $this->mailbox['OPTIONS']['flags']);
		}

		if ($needUpload)
		{
			if ($excerpt['UPLOAD_STAGE'] < 2)
			{
				Mail\Internals\MessageUploadQueueTable::update(
					array(
						'ID' => $excerpt['ID'],
						'MAILBOX_ID' => $excerpt['MAILBOX_ID'],
					),
					array(
						'SYNC_STAGE' => 2,
						'ATTEMPTS' => 1,
					)
				);
			}

			class_exists('Bitrix\Mail\Helper');

			$message = new Mail\DummyMail(array_merge(
				$outgoingParams,
				array(
					'HEADER' => array_merge(
						$outgoingParams['HEADER'],
						array(
							'To'      => $outgoingParams['TO'],
							'Subject' => $outgoingParams['SUBJECT'],
						)
					),
				)
			));

			if ($this->uploadMessage($message, $excerpt))
			{
				Mail\Internals\MessageUploadQueueTable::delete(array(
					'ID' => $excerpt['ID'],
					'MAILBOX_ID' => $excerpt['MAILBOX_ID'],
				));
			}
		}
		else
		{
			Mail\Internals\MessageUploadQueueTable::update(
				array(
					'ID' => $excerpt['ID'],
					'MAILBOX_ID' => $excerpt['MAILBOX_ID'],
				),
				array(
					'SYNC_STAGE' => -1,
					'SYNC_LOCK' => 0,
				)
			);
		}

		return;
	}

	/**
	 * A refusal of the sender is final at once, since a retry goes through the same record. Any other failure
	 * is retried, and becomes final only when the last attempt fails too: past it the queue skips the letter.
	 */
	private function resolvePermanentSendError(array $excerpt, object $context, ?Main\Result $sendResult): ?Main\Error
	{
		$controlledError = SendFailure::getControlledError($sendResult) ?? $this->getContextSendingError($context);
		if ($controlledError !== null)
		{
			return $controlledError;
		}

		if ((int)$excerpt['UPLOAD_ATTEMPTS'] + 1 < self::MAX_OUTGOING_ATTEMPTS)
		{
			return null;
		}

		$transportMessage = $sendResult?->getErrorMessages()[0] ?? '';

		return new Main\Error($transportMessage ?: 'Failed to send email.', SendFailure::CODE_SEND_FAILED);
	}

	private function getContextSendingError(object $context): ?Main\Error
	{
		return method_exists($context, 'getSendingError') ? $context->getSendingError() : null;
	}

	private function completeOutgoingWithError(array $excerpt, Main\Error $error): void
	{
		Mail\Internals\MessageUploadQueueTable::update(
			[
				'ID' => $excerpt['ID'],
				'MAILBOX_ID' => $excerpt['MAILBOX_ID'],
			],
			[
				'SYNC_LOCK' => 0,
				'ATTEMPTS' => self::MAX_OUTGOING_ATTEMPTS,
			],
		);

		$options = is_array($excerpt['__OPTIONS'] ?? null) ? $excerpt['__OPTIONS'] : [];
		SendFailure::markMessageFailed((int)$excerpt['__ID'], $options, $error);

		Mail\Integration\Im\Notification::notifyUserAboutMessageSendFailed(
			(int)($options[self::OUTGOING_AUTHOR_OPTION] ?? 0),
			(int)$excerpt['__ID'],
			(string)($excerpt['__SUBJECT'] ?? ''),
			(string)$error->getCode(),
		);
	}

	public static function getPermanentSendError(array $message): ?Main\Error
	{
		return SendFailure::getMessageFailure($message);
	}

	/**
	 * Tells the kernel which mailbox the message belongs to, so that the transport, the limit and the
	 * counter are scoped to its own sender record. Older kernels keep selecting by the address.
	 */
	private function applySenderIdentity(Main\Mail\Context $context, int $mailboxId): void
	{
		if (
			$mailboxId <= 0
			|| !class_exists(Main\Mail\Sender\Identity::class)
			|| !method_exists($context, 'setSenderIdentity')
		)
		{
			return;
		}

		$context->setSenderIdentity(Main\Mail\Sender\Identity::fromMailbox($mailboxId));
	}

	public function resyncMessage(array &$excerpt)
	{
		$body = $this->downloadMessage($excerpt);
		if (!empty($body))
		{
			return $this->cacheMessage(
				$body,
				array(
					'replaces' => $excerpt['ID'],
				)
			);
		}

		return false;
	}

	public function downloadAttachments(array &$excerpt)
	{
		$body = $this->downloadMessage($excerpt);
		if (!empty($body))
		{
			[,,, $attachments] = \CMailMessage::parseMessage($body, $this->mailbox['LANG_CHARSET']);

			return $attachments;
		}

		return false;
	}

	public function isSupportLazyAttachments()
	{
		foreach ($this->getFilters() as $filter)
		{
			foreach ($filter['__actions'] as $action)
			{
				if (empty($action['LAZY_ATTACHMENTS']))
				{
					return false;
				}
			}
		}

		return true;
	}

	public function getFilters($force = false)
	{
		if (is_null($this->filters) || $force)
		{
			$this->filters = Mail\MailFilterTable::getList(array(
				'filter' => ORM\Query\Query::filter()
					->where('ACTIVE', 'Y')
					->where(
						ORM\Query\Query::filter()->logic('or')
							->where('MAILBOX_ID', $this->mailbox['ID'])
							->where('MAILBOX_ID', null)
					),
				'order' => array(
					'SORT' => 'ASC',
					'ID' => 'ASC',
				),
			))->fetchAll();

			foreach ($this->filters as $k => $item)
			{
				$this->filters[$k]['__actions'] = array();

				$res = \CMailFilter::getFilterList($item['ACTION_TYPE']);
				while ($row = $res->fetch())
				{
					$this->filters[$k]['__actions'][] = $row;
				}
			}
		}

		return $this->filters;
	}

	/**
	 * @deprecated
	 */
	public function resortTree($message = null)
	{
		global $DB;

		$worker = function ($id, $msgId, &$i)
		{
			global $DB;

			$stack = array(
				array(
					array($id, $msgId, false),
				),
			);

			$excerpt = array();

			do
			{
				$level = array_pop($stack);

				while ($level)
				{
					[$id, $msgId, $skip] = array_shift($level);

					if (!$skip)
					{
						$excerpt[] = $id;

						$DB->query(sprintf(
							'UPDATE b_mail_message SET LEFT_MARGIN = %2$u, RIGHT_MARGIN = %3$u WHERE ID = %1$u',
							$id, ++$i, ++$i
						));

						if (!empty($msgId))
						{
							$replies = array();

							$res = Mail\MailMessageTable::getList(array(
								'select' => array(
									'ID',
									'MSG_ID',
								),
								'filter' => array(
									'=MAILBOX_ID' => $this->mailbox['ID'],
									'=IN_REPLY_TO' => $msgId,
								),
								'order' => array(
									'FIELD_DATE' => 'ASC',
								),
							));

							while ($item = $res->fetch())
							{
								if (!in_array($item['ID'], $excerpt))
								{
									$replies[] = array($item['ID'], $item['MSG_ID'], false);
								}
							}

							if ($replies)
							{
								array_unshift($level, array($id, $msgId, true));

								array_push($stack, $level, $replies);
								$i--;

								continue 2;
							}
						}
					}
					else
					{
						$DB->query(sprintf(
							'UPDATE b_mail_message SET RIGHT_MARGIN = %2$u WHERE ID = %1$u',
							$id, ++$i
						));
					}
				}
			}
			while ($stack);
		};

		if (!empty($message))
		{
			if (empty($message['ID']))
			{
				throw new Main\ArgumentException("Argument 'message' is not valid");
			}

			$item = $DB->query(sprintf(
				'SELECT GREATEST(M1, M2) AS I FROM (SELECT
					(SELECT RIGHT_MARGIN FROM b_mail_message WHERE MAILBOX_ID = %1$u AND RIGHT_MARGIN > 0 ORDER BY LEFT_MARGIN ASC LIMIT 1) M1,
					(SELECT RIGHT_MARGIN FROM b_mail_message WHERE MAILBOX_ID = %1$u AND RIGHT_MARGIN > 0 ORDER BY LEFT_MARGIN DESC LIMIT 1) M2
				) M',
				$this->mailbox['ID']
			))->fetch();

			$i = empty($item['I']) ? 0 : $item['I'];

			$worker($message['ID'], $message['MSG_ID'], $i);
		}
		else
		{
			$DB->query(sprintf(
				'UPDATE b_mail_message SET LEFT_MARGIN = 0, RIGHT_MARGIN = 0 WHERE MAILBOX_ID = %u',
				$this->mailbox['ID']
			));

			$i = 0;

			$res = $DB->query(sprintf(
				"SELECT ID, MSG_ID FROM b_mail_message M WHERE MAILBOX_ID = %u AND (
					IN_REPLY_TO IS NULL OR IN_REPLY_TO = '' OR NOT EXISTS (
						SELECT 1 FROM b_mail_message WHERE MAILBOX_ID = M.MAILBOX_ID AND MSG_ID = M.IN_REPLY_TO
					)
				)",
				$this->mailbox['ID']
			));

			while ($item = $res->fetch())
			{
				$worker($item['ID'], $item['MSG_ID'], $i);
			}

			// crosslinked messages
			$query = sprintf(
				'SELECT ID, MSG_ID FROM b_mail_message
					WHERE MAILBOX_ID = %u AND LEFT_MARGIN = 0
					ORDER BY FIELD_DATE ASC LIMIT 1',
				$this->mailbox['ID']
			);
			while ($item = $DB->query($query)->fetch())
			{
				$worker($item['ID'], $item['MSG_ID'], $i);
			}
		}
	}

	/**
	 * @deprecated
	 */
	public function incrementTree($message)
	{
		if (empty($message['ID']))
		{
			throw new Main\ArgumentException("Argument 'message' is not valid");
		}

		if (!empty($message['IN_REPLY_TO']))
		{
			$item = Mail\MailMessageTable::getList(array(
				'select' => array(
					'ID', 'MSG_ID', 'LEFT_MARGIN', 'RIGHT_MARGIN',
				),
				'filter' => array(
					'=MAILBOX_ID' => $this->mailbox['ID'],
					'=MSG_ID' => $message['IN_REPLY_TO'],
				),
				'order' => array(
					'LEFT_MARGIN' => 'ASC',
				),
			))->fetch();

			if (!empty($item))
			{
				$message = $item;

				$item = Mail\MailMessageTable::getList(array(
					'select' => array(
						'ID', 'MSG_ID',
					),
					'filter' => array(
						'=MAILBOX_ID' => $this->mailbox['ID'],
						'<LEFT_MARGIN' => $item['LEFT_MARGIN'],
						'>RIGHT_MARGIN' => $item['RIGHT_MARGIN'],
					),
					'order' => array(
						'LEFT_MARGIN' => 'ASC',
					),
					'limit' => 1,
				))->fetch();

				if (!empty($item))
				{
					$message = $item;
				}
			}
		}

		$this->resortTree($message);
	}

	/**
	 * @param string $dirPath
	 * @param array $UIDs
	 * @return array uids of the messages still present in the folder on the mail server
	 */
	abstract public function checkMessagesForExistence($dirPath ='INBOX',$UIDs = []);
	abstract public function resyncIsOldStatus();
	abstract public function syncFirstDay();
	abstract protected function syncInternal();
	abstract public function listDirs($pattern, $useDb = false);
	abstract public function uploadMessage(Main\Mail\Mail $message, array &$excerpt);
	abstract public function downloadMessage(array &$excerpt);
	abstract public function syncMessages($mailboxID, $dirPath, $UIDs);
	abstract public function isAuthenticated();

	public function getErrors()
	{
		return $this->errors;
	}

	public function getWarnings()
	{
		return $this->warnings;
	}

	public function getLastSyncResult()
	{
		return $this->lastSyncResult;
	}

	protected function setLastSyncResult(array $data)
	{
		$this->lastSyncResult = array_merge($this->lastSyncResult, $data);
	}

	public function getDirsHelper(?int $userId = null): Mail\Helper\MailboxDirectoryHelper
	{
		$scope = $this->peekDirectoryScope();

		if ($userId !== null)
		{
			return new Mail\Helper\MailboxDirectoryHelper($this->mailbox['ID'], $userId, $scope);
		}

		// An injected migration context changes the scope: rebuild the cached helper
		$scopeKey = $scope->getCacheKey();
		if (!$this->dirsHelper || $this->dirsHelperScopeKey !== $scopeKey)
		{
			$this->dirsHelper = new Mail\Helper\MailboxDirectoryHelper($this->mailbox['ID'], null, $scope);
			$this->dirsHelperScopeKey = $scopeKey;
		}

		return $this->dirsHelper;
	}

	public function activateSync()
	{
		$options = $this->mailbox['OPTIONS'];

		if (!isset($options['activateSync']) || $options['activateSync'] === true)
		{
			return false;
		}

		$entity = MailboxTable::getEntity();
		$connection = $entity->getConnection();

		$options['activateSync'] = true;

		$query = sprintf(
			'UPDATE %s SET %s WHERE %s',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			$connection->getSqlHelper()->prepareUpdate($entity->getDbTableName(), [
				'SYNC_LOCK' => 0,
				'OPTIONS'   => serialize($options),
			])[0],
			Query::buildFilterSql(
				$entity,
				[
					'ID' => $this->mailbox['ID']
				]
			)
		);

		return $connection->query($query);
	}

	public function notifyNewMessages()
	{
		// A migration import moves the history the user has already been notified about
		if ($this->generationContext?->isMigrationImport())
		{
			return;
		}

		if (Loader::includeModule('im'))
		{
			$lastSyncResult = $this->getLastSyncResult();
			$count = $lastSyncResult['newMessagesNotify'];
			$newMessageId = $lastSyncResult['newMessageId'];
			$message = null;

			if ($count < 1)
			{
				return;
			}

			if ($newMessageId > 0 && $count === 1)
			{
				$message = Mail\MailMessageTable::getByPrimary(
					$newMessageId,
					[
						'select' => [
							'ID',
							'HEADER',
							'FIELD_FROM',
							'FIELD_REPLY_TO',
							'FIELD_TO',
							'FIELD_CC',
							'FIELD_BCC',
							'BODY_HTML',
							'SUBJECT',
						],
						'limit' => 1,
					],
				)->fetch();

				if (!empty($message))
				{
					Mail\Helper\Message::prepare($message);
				}
			}

			Mail\Integration\Im\Notification::add(
				$this->mailbox['USER_ID'],
				Mail\Integration\Im\Notification::notifierSchemeTypeMail,
				array(
					'mailboxOwnerId' => $this->mailbox['USER_ID'],
					'mailboxId' => $this->mailbox['ID'],
					'count' => $count,
					'message' => $message,
				)
			);
		}
	}

	/**
	 * Could we sanitize message on view?
	 * if there is no filters that can use sanitized body
	 *
	 * @return bool
	 */
	public function isSupportSanitizeOnView(): bool
	{
		$supportedActionTypes = [
			// doesn't use BODY_HTML or BODY_BB
			"forumsocnet",
			"support",
			"crm",
		];
		foreach ($this->getFilters() as $filter)
		{
			if (
				!in_array($filter['ACTION_TYPE'], $supportedActionTypes, true)
				&& (
					$this->hasActionWithoutSanitizeSupport($filter['__actions'])
					|| !empty($filter['PHP_CONDITION'])
					|| !empty($filter['ACTION_PHP'])
				)
			)
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * Is any action that don't have sanitize on view support
	 *
	 * @param array|null|false $actions Filter actions
	 *
	 * @return bool
	 */
	private function hasActionWithoutSanitizeSupport($actions): bool
	{
		if (is_array($actions))
		{
			foreach ($actions as $action)
			{
				if (empty($action['SANITIZE_ON_VIEW']))
				{
					return true;
				}
			}
		}
		return false;
	}

	/*
	Returns the minimum time between possible re-synchronization
	The time is taken from the option 'max_execution_time', but no more than static::SYNC_TIMEOUT
	*/
	final public static function getTimeout()
	{
		return min(max(0, ini_get('max_execution_time')) ?: static::SYNC_TIMEOUT, static::SYNC_TIMEOUT);
	}

	final public static function getForUserByEmail($email)
	{
		$mailbox = Mail\MailboxTable::getUserMailboxWithEmail($email);
		if (isset($mailbox['EMAIL']))
		{
			return static::createInstance($mailbox['ID'], false);
		}

		return null;
	}

	final public static function findBy($id, ?string $email = null, ?int $userId = null): ?Mailbox
	{
		$instance = null;

		if ($id > 0)
		{
			if ($mailbox = Mail\MailboxTable::getUserMailbox($id, $userId))
			{
				$instance = static::createInstance($mailbox['ID'], false);
			}
		}

		if (!empty($email) && empty($instance))
		{
			$instance = static::getForUserByEmail($email);
		}

		if (!empty($instance))
		{
			return $instance;
		}

		return null;
	}

	public static function getIdByMessageId(int $messageId): int
	{
		if (!$messageId)
		{
			return 0;
		}

		$res = MailMessageTable::query()
			->setSelect(['MAILBOX_ID'])
			->where('ID', $messageId)
			->exec()
		;

		if ($row = $res->fetch())
		{
			return (int)$row['MAILBOX_ID'];
		}

		return 0;
	}

	/**
	 * @deprecated Use \Bitrix\Mail\Internal\Service\Mailbox\MailboxEmailOccupancyService instead: it
	 * answers the same question about one person with isAddressHeldByUser(), and the same question
	 * about the whole portal with checkOccupancy(). The lookup behind this method moved there and no
	 * longer depends on the site, so $lid is ignored; a mailbox waiting for its password is no longer
	 * a match either.
	 *
	 * @param int $userId
	 * @param string $email
	 * @param string $lid
	 * @return array|false
	 */
	public static function findActiveMailbox($userId, $email, $lid)
	{
		$mailboxId = (new MailboxEmailOccupancyService())->findActiveMailboxIdOfUser((string)$email, (int)$userId);
		if ($mailboxId === null)
		{
			return false;
		}

		return MailboxTable::getById($mailboxId)->fetch();
	}
}
