<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Helper\Dto\Message\SearchMessagesDto;
use Bitrix\Mail\Helper\Mailbox as HelperMailbox;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Helper\Message\Loader\MessageFilter;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Helper\MessageFolder;
use Bitrix\Mail\Internal\Service\Message\QuoteTrimmer;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Application;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;

class MessageSearch
{
	private const COMPACT_BODY_LENGTH = 10000;

	public const THREAD_PAGE_SIZE_DEFAULT = 20;

	public const MAX_THREAD_PAGE_SIZE_WITH_BODIES = 50;
	public const MAX_THREAD_PAGE_SIZE_HEADERS = 100;

	public const MAX_ASSEMBLED_THREAD_MESSAGES = MessageThreadLoader::MAX_THREAD_MESSAGE_IDS;

	private const MAX_THREAD_CANDIDATES = self::MAX_ASSEMBLED_THREAD_MESSAGES;

	/** Access checks spent on a crafted list of inaccessible ids; a readable message ranks first. */
	private const MAX_ACCESS_PROBES = 25;

	/** @var array<string, bool> "mailboxId:userId" => access decision */
	private array $mailboxAccessCache = [];

	/**
	 * @throws SystemException|LoaderException
	 */
	public function search(SearchMessagesDto $dto, int $userId): array
	{
		$filter = $this->buildFilterFromDto($dto, $userId);
		if ($filter === null)
		{
			return [];
		}

		$listQuery = QueryBuilder::buildMailMessageListQuery(
			$filter,
			$dto->limit > 0 ? $dto->limit : SearchMessagesDto::DEFAULT_LIMIT,
			max(0, $dto->offset),
		);

		$itemIds = array_column($listQuery->fetchAll(), 'DISTINCT_ID');
		if (empty($itemIds))
		{
			return [];
		}

		$detailsQuery = QueryBuilder::buildDefaultMessagesDetailsQuery(
			$itemIds,
			$filter
		);

		return $this->formatMessages($detailsQuery->fetchAll());
	}

	/**
	 * Counts messages across user's mailboxes using the same filters as {@see self::search()}.
	 *
	 * @throws SystemException|LoaderException
	 */
	public function count(SearchMessagesDto $dto, int $userId): int
	{
		$filter = $this->buildFilterFromDto($dto, $userId);
		if ($filter === null)
		{
			return 0;
		}

		return QueryBuilder::countMailMessages($filter);
	}

	private function resolveMailboxIds(?int $mailboxId, int $userId): array
	{
		return MailboxAccess::resolveUserMailboxIds($mailboxId, $userId);
	}

	private function resolveFolderInDto(SearchMessagesDto $dto, array $mailboxIds): SearchMessagesDto
	{
		if ($dto->folder === null || trim($dto->folder) === '')
		{
			return $dto;
		}

		$resolvedPath = $this->resolveFolderPath($dto->folder, $mailboxIds);
		if ($resolvedPath === null || $resolvedPath === $dto->folder)
		{
			return $dto;
		}

		return new SearchMessagesDto(
			mailboxId: $dto->mailboxId,
			searchQuery: $dto->searchQuery,
			dateFrom: $dto->dateFrom,
			dateTo: $dto->dateTo,
			isSeen: $dto->isSeen,
			hasAttachments: $dto->hasAttachments,
			folder: $resolvedPath,
			bindings: $dto->bindings,
			excludeBindings: $dto->excludeBindings,
			limit: $dto->limit,
			offset: $dto->offset,
			classification: $dto->classification,
			unanswered: $dto->unanswered,
		);
	}

	/**
	 * @throws SystemException
	 */
	private function buildFilterFromDto(SearchMessagesDto $dto, int $userId): ?array
	{
		$mailboxIds = $this->resolveMailboxIds($dto->mailboxId, $userId);
		if (empty($mailboxIds))
		{
			return null;
		}

		$dto = $this->resolveFolderInDto($dto, $mailboxIds);

		$messageFilter = (new MessageFilter($mailboxIds, []));
		$messageFilter->applyFromDto($dto);

		return $messageFilter->getArray();
	}

	private function resolveFolderPath(string $folder, array $mailboxIds): ?string
	{
		foreach ($mailboxIds as $mailboxId)
		{
			$resolved = MessageFolder::resolveFolderPath($folder, $mailboxId);
			if ($resolved !== null)
			{
				return $resolved;
			}
		}

		return null;
	}

	/** @throws SystemException */
	public function getMessageContent(int $messageId, int $userId): array
	{
		$message = Message::getWithAccessCheck($messageId, $userId);
		if ($message === null)
		{
			throw new SystemException('Message not found or access denied.');
		}

		return [
			'id' => $messageId,
			'subject' => $message['SUBJECT'] ?? '',
			'from' => $message['FIELD_FROM'] ?? '',
			'to' => $message['FIELD_TO'] ?? '',
			'cc' => $message['FIELD_CC'] ?? '',
			'date' => ($message['INTERNALDATE'] ?? $message['FIELD_DATE']) instanceof DateTime
				? ($message['INTERNALDATE'] ?? $message['FIELD_DATE'])->format('Y-m-d H:i:s')
				: (string)($message['INTERNALDATE'] ?? $message['FIELD_DATE'] ?? ''),
			'body' => $this->sanitizeBody($message),
		];
	}

	/**
	 * @return array{id: int, subject: string, from: string, to: string, cc: string, date: string,
	 *               body: string, truncated: bool, bodyLength: int}
	 * @throws SystemException
	 */
	public function getMessageContentCompact(int $messageId, int $userId): array
	{
		if ($this->resolveAccessibleMessage($messageId, $userId) === null)
		{
			throw new SystemException('Message not found or access denied.');
		}

		$rows = MailMessageTable::getList([
			'runtime' => array_merge(
				[self::buildMessageUidReferenceField()],
				self::buildCompactBodyRuntimeFields(),
			),
			'select' => [
				'ID', 'SUBJECT', 'FIELD_FROM', 'FIELD_TO', 'FIELD_CC',
				'FIELD_DATE', 'BODY_CAPPED', 'BODY_LEN',
			],
			'filter' => ['=ID' => $messageId],
			'limit' => 1,
		])->fetchAll();

		if (empty($rows))
		{
			throw new SystemException('Message not found or access denied.');
		}

		$row = self::attachHtmlFallback([$messageId => $rows[0]])[$messageId];
		$dateSource = $row['FIELD_DATE'];
		$date = $dateSource instanceof DateTime
			? $dateSource->format('Y-m-d H:i:s')
			: (string)($dateSource ?? '');

		$bodyInfo = $this->prepareCompactBody($row);

		return [
			'id' => $messageId,
			'subject' => $row['SUBJECT'] ?? '',
			'from' => $row['FIELD_FROM'] ?? '',
			'to' => $row['FIELD_TO'] ?? '',
			'cc' => $row['FIELD_CC'] ?? '',
			'date' => $date,
			'body' => $bodyInfo['body'],
			'truncated' => $bodyInfo['truncated'],
			'bodyLength' => $bodyInfo['bodyLength'],
		];
	}

	public function getReplyContextBody(int $messageId, int $userId): ?string
	{
		$query = MailMessageTable::query();
		foreach (self::buildCompactBodyRuntimeFields() as $runtimeField)
		{
			$query->registerRuntimeField($runtimeField);
		}

		$row = $query
			->setSelect(['ID', 'MAILBOX_ID', 'BODY_CAPPED', 'BODY_LEN'])
			->where('ID', $messageId)
			->setLimit(1)
			->exec()
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		// Message::hasAccess stays the fallback: it also grants by the crm_mail_reply token and by
		// entity bindings, at the price of reading the whole message row including the bodies
		$hasAccess = $this->hasActiveMailboxAccess((int)($row['MAILBOX_ID'] ?? 0), $userId)
			|| Message::hasAccess($row, $userId)
		;
		if (!$hasAccess)
		{
			return null;
		}

		$row = self::attachHtmlFallback([$messageId => $row])[$messageId];

		return $this->prepareCompactBody($row, false)['body'];
	}

	/** @throws SystemException */
	public function getMessageById(int $messageId, int $userId): array
	{
		$content = $this->getMessageContent($messageId, $userId);

		$rows = QueryBuilder::buildDefaultMessagesDetailsQuery([$messageId], [])->fetchAll();
		$formatted = $this->formatMessages($rows);
		if (empty($formatted))
		{
			throw new SystemException('Message details not available.');
		}

		return $formatted[0] + [
			'cc' => $content['cc'] ?? '',
			'body' => $content['body'] ?? '',
		];
	}

	protected function resolveAccessibleMessage(int $messageId, int $userId): ?array
	{
		if ($userId <= 0)
		{
			return null;
		}

		$mailboxId = HelperMailbox::getIdByMessageId($messageId);
		if ($mailboxId <= 0)
		{
			return null;
		}

		if (!$this->hasActiveMailboxAccess($mailboxId, $userId))
		{
			return null;
		}

		return ['ID' => $messageId, 'MAILBOX_ID' => $mailboxId];
	}

	/**
	 * Mailbox deletion first flips ACTIVE to 'N' and only a deferred agent removes the messages and
	 * the access codes, so without ACTIVE/SERVER_TYPE the owner keeps reading a deleted mailbox.
	 */
	private function hasActiveMailboxAccess(int $mailboxId, int $userId): bool
	{
		if ($mailboxId <= 0 || $userId <= 0)
		{
			return false;
		}

		$cacheKey = $mailboxId . ':' . $userId;
		if (isset($this->mailboxAccessCache[$cacheKey]))
		{
			return $this->mailboxAccessCache[$cacheKey];
		}

		$isLiveMailbox = (bool)MailboxTable::query()
			->setSelect(['ID'])
			->where('ID', $mailboxId)
			->where('ACTIVE', 'Y')
			->where('SERVER_TYPE', 'imap')
			->setLimit(1)
			->fetch()
		;

		$hasAccess = $isLiveMailbox && MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true);
		$this->mailboxAccessCache[$cacheKey] = $hasAccess;

		return $hasAccess;
	}

	/**
	 * One page of the branch the message belongs to, taken from the newest end of it; inside a page
	 * messages are ordered oldest first.
	 *
	 * @return array{messages: array, total: int, hasMore: bool}
	 * @throws SystemException
	 */
	public function getMessageThread(
		int $messageId,
		int $userId,
		int $limit = self::THREAD_PAGE_SIZE_DEFAULT,
		int $offset = 0,
		bool $withBodies = true,
	): array {
		$access = $this->resolveAccessibleMessage($messageId, $userId);
		if ($access === null)
		{
			throw new SystemException('Message not found or access denied.');
		}

		$limit = self::clampThreadPageSize($limit, $withBodies);
		$offset = max(0, $offset);

		$threadLoader = new MessageThreadLoader($messageId);
		$threadLoader->loadThreadBranchMessageIds();
		$threadMessageIds = $threadLoader->getThreadMessageIds();

		if (empty($threadMessageIds))
		{
			return ['messages' => [], 'total' => 0, 'hasMore' => false];
		}

		$mailboxId = (int)$access['MAILBOX_ID'];

		$total = $this->countVisibleThreadMessages($threadMessageIds, $mailboxId);
		if ($total === 0)
		{
			return ['messages' => [], 'total' => 0, 'hasMore' => false];
		}

		$datesById = $this->selectThreadMessagePage($threadMessageIds, $mailboxId, $limit, $offset);

		$datesById = array_reverse($datesById, true);

		$rowMap = $this->loadThreadMessageRows(array_keys($datesById), $withBodies);

		$messages = [];
		foreach ($datesById as $id => $internaldate)
		{
			$row = $rowMap[$id] ?? [];
			$date = $internaldate ?? $row['FIELD_DATE'] ?? null;

			$item = [
				'id' => $id,
				'subject' => $row['SUBJECT'] ?? '',
				'from' => $row['FIELD_FROM'] ?? '',
				'to' => $row['FIELD_TO'] ?? '',
				'cc' => $row['FIELD_CC'] ?? '',
				'date' => $date instanceof DateTime ? $date->format('Y-m-d H:i:s') : (string)($date ?? ''),
			];

			if ($withBodies)
			{
				$bodyInfo = $this->prepareCompactBody($row);
				$item['body'] = $bodyInfo['body'];
				$item['truncated'] = $bodyInfo['truncated'];
				$item['bodyLength'] = $bodyInfo['bodyLength'];
			}

			$messages[] = $item;
		}

		return [
			'messages' => $messages,
			'total' => $total,
			'hasMore' => ($offset + $limit) < $total,
		];
	}

	public static function clampThreadPageSize(int $limit, bool $withBodies): int
	{
		if ($limit <= 0)
		{
			return self::THREAD_PAGE_SIZE_DEFAULT;
		}

		return min(
			$limit,
			$withBodies ? self::MAX_THREAD_PAGE_SIZE_WITH_BODIES : self::MAX_THREAD_PAGE_SIZE_HEADERS,
		);
	}

	/**
	 * A message kept in several folders has a uid row per folder, so the join multiplies rows:
	 * MAX(INTERNALDATE) grouped by message id makes limit and offset count messages, not joined rows.
	 *
	 * @return array<int, mixed> receive date by message id, newest first
	 */
	private function selectThreadMessagePage(
		array $threadMessageIds,
		int $mailboxId,
		int $limit,
		int $offset,
	): array
	{
		$rows = MailMessageTable::getList([
			'runtime' => [
				self::buildMessageUidReferenceField(),
				self::buildReceiveDateField('INTERNALDATE_MAX', aggregated: true),
			],
			'select' => ['ID', 'INTERNALDATE_MAX'],
			'filter' => self::buildVisibleThreadFilter($threadMessageIds, $mailboxId),
			'order' => ['INTERNALDATE_MAX' => 'DESC', 'ID' => 'DESC'],
			'limit' => $limit,
			'offset' => $offset,
		])->fetchAll();

		$dates = [];
		foreach ($rows as $row)
		{
			$dates[(int)$row['ID']] = $row['INTERNALDATE_MAX'];
		}

		return $dates;
	}

	/**
	 * INTERNALDATE is nullable and NULL ordering under DESC differs between mysql and pgsql, so the
	 * fallback has to live in SQL rather than after the rows are picked: otherwise a message without
	 * a server date would page differently per dbms. DATE_INSERT is the only always-filled column.
	 */
	private static function buildReceiveDateField(string $name, bool $aggregated): \Bitrix\Main\Entity\ExpressionField
	{
		$expression = 'COALESCE(%s, %s, %s)';

		return (new \Bitrix\Main\Entity\ExpressionField(
			$name,
			$aggregated ? "MAX($expression)" : $expression,
			['MESSAGE_UID.INTERNALDATE', 'FIELD_DATE', 'DATE_INSERT'],
		))->configureValueType(\Bitrix\Main\ORM\Fields\DatetimeField::class);
	}

	/**
	 * COUNT(DISTINCT ID) because the uid join carries a row per folder a message is kept in.
	 * Clamped to the assembly ceiling: 'total' must promise no more than paging can reach.
	 */
	private function countVisibleThreadMessages(array $threadMessageIds, int $mailboxId): int
	{
		$visibleCount = new \Bitrix\Main\Entity\ExpressionField(
			'VISIBLE_COUNT',
			'COUNT(DISTINCT %s)',
			'ID',
		);

		$row = MailMessageTable::getList([
			'runtime' => [self::buildMessageUidReferenceField(), $visibleCount],
			'select' => ['VISIBLE_COUNT'],
			'filter' => self::buildVisibleThreadFilter($threadMessageIds, $mailboxId),
		])->fetch();

		return min((int)($row['VISIBLE_COUNT'] ?? 0), self::MAX_ASSEMBLED_THREAD_MESSAGES);
	}

	private static function buildVisibleThreadFilter(array $threadMessageIds, int $mailboxId): array
	{
		return [
			'@ID' => $threadMessageIds,
			'=MAILBOX_ID' => $mailboxId,
			'==MESSAGE_UID.DELETE_TIME' => 0,
			'!@MESSAGE_UID.IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
		];
	}

	/**
	 * Access is applied after ranking but before returning, so an injected inaccessible id cannot
	 * serve as an existence oracle for a foreign newer message.
	 */
	public function getLatestVisibleMessageId(array $messageIds, int $userId): ?int
	{
		// size first, before normalisation copies a client-controlled list
		if ($userId <= 0 || count($messageIds) > self::MAX_THREAD_CANDIDATES)
		{
			return null;
		}

		$messageIds = array_values(array_unique(array_filter(
			array_map('intval', $messageIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($messageIds))
		{
			return null;
		}

		$rows = MailMessageTable::getList([
			'runtime' => [
				self::buildMessageUidReferenceField(),
				self::buildReceiveDateField('RECEIVE_DATE', aggregated: false),
			],
			'select' => ['ID', 'MAILBOX_ID'],
			'filter' => [
				'@ID' => $messageIds,
				'==MESSAGE_UID.DELETE_TIME' => 0,
				'!@MESSAGE_UID.IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
			],
			'order' => ['RECEIVE_DATE' => 'DESC', 'ID' => 'DESC'],
			'limit' => self::MAX_THREAD_CANDIDATES,
		])->fetchAll();

		$seen = [];
		$probes = 0;
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			if (isset($seen[$id]))
			{
				continue;
			}
			$seen[$id] = true;

			if ($probes >= self::MAX_ACCESS_PROBES)
			{
				break;
			}
			$probes++;

			if ($this->isAccessibleMessage($row, $userId))
			{
				return $id;
			}
		}

		return null;
	}

	/**
	 * Message::hasAccess runs only with a CRM token present: it would read whole message rows for
	 * every candidate. Fail-closed - an error skips the candidate instead of aborting the ranking.
	 */
	protected function isAccessibleMessage(array $row, int $userId): bool
	{
		try
		{
			if ($this->hasActiveMailboxAccess((int)($row['MAILBOX_ID'] ?? 0), $userId))
			{
				return true;
			}

			if (!isset($_REQUEST['mail_uf_message_token']))
			{
				return false;
			}

			return Message::hasAccess($row, $userId);
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	/** Visibility is already settled by selectThreadMessagePage(), so no uid join is needed here. */
	private function loadThreadMessageRows(array $messageIds, bool $withBodies): array
	{
		if (empty($messageIds))
		{
			return [];
		}

		$select = ['ID', 'SUBJECT', 'FIELD_FROM', 'FIELD_TO', 'FIELD_CC', 'FIELD_DATE'];
		$runtime = [];
		if ($withBodies)
		{
			$select = array_merge($select, ['BODY_CAPPED', 'BODY_LEN']);
			$runtime = self::buildCompactBodyRuntimeFields();
		}

		$rows = MailMessageTable::getList([
			'runtime' => $runtime,
			'select' => $select,
			'filter' => ['@ID' => $messageIds],
		])->fetchAll();

		$map = [];
		foreach ($rows as $row)
		{
			$map[(int)$row['ID']] = $row;
		}

		return $withBodies ? self::attachHtmlFallback($map) : $map;
	}

	private static function buildMessageUidReferenceField(): \Bitrix\Main\Entity\ReferenceField
	{
		return new \Bitrix\Main\Entity\ReferenceField(
			'MESSAGE_UID',
			MailMessageUidTable::class,
			[
				'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
				'=this.ID' => 'ref.MESSAGE_ID',
			],
			['join_type' => 'INNER'],
		);
	}

	private static function buildCompactBodyRuntimeFields(): array
	{
		return self::buildCappedColumnFields('BODY', 'BODY_CAPPED', 'BODY_LEN');
	}

	private static function buildHtmlFallbackRuntimeFields(): array
	{
		return self::buildCappedColumnFields('BODY_HTML', 'HTML_CAPPED', 'HTML_LEN');
	}

	private static function buildCappedColumnFields(string $column, string $cappedName, string $lengthName): array
	{
		$sqlHelper = Application::getConnection()->getSqlHelper();

		return [
			new \Bitrix\Main\Entity\ExpressionField(
				$cappedName,
				$sqlHelper->getSubstrFunction('%s', 1, self::COMPACT_BODY_LENGTH),
				$column,
			),
			// Not the core getLengthFunction(): it counts bytes, while the cap here is in characters.
			new \Bitrix\Main\Entity\ExpressionField(
				$lengthName,
				'CHAR_LENGTH(%s)',
				$column,
			),
		];
	}

	/**
	 * A separate query so the hot path never touches BODY_HTML: CHAR_LENGTH over a longtext makes
	 * the dbms read the whole column, while only messages stored with an empty BODY need it.
	 */
	private static function attachHtmlFallback(array $rows): array
	{
		$idsNeedingHtml = [];
		foreach ($rows as $id => $row)
		{
			if (self::isCompactSourceEmpty((string)($row['BODY_CAPPED'] ?? ''), (int)($row['BODY_LEN'] ?? 0)))
			{
				$idsNeedingHtml[] = $id;
			}
		}

		if (empty($idsNeedingHtml))
		{
			return $rows;
		}

		$htmlRows = MailMessageTable::getList([
			'runtime' => self::buildHtmlFallbackRuntimeFields(),
			'select' => ['ID', 'HTML_CAPPED', 'HTML_LEN'],
			'filter' => ['@ID' => $idsNeedingHtml],
		])->fetchAll();

		foreach ($htmlRows as $htmlRow)
		{
			$id = (int)$htmlRow['ID'];
			if (isset($rows[$id]))
			{
				$rows[$id]['HTML_CAPPED'] = $htmlRow['HTML_CAPPED'];
				$rows[$id]['HTML_LEN'] = $htmlRow['HTML_LEN'];
			}
		}

		return $rows;
	}

	/**
	 * A blank capped prefix proves the source is empty only while the source is not capped: past the
	 * cap the meaningful text may simply start beyond COMPACT_BODY_LENGTH.
	 */
	private static function isCompactSourceEmpty(string $capped, int $length): bool
	{
		return $length === 0 || ($length <= self::COMPACT_BODY_LENGTH && trim($capped) === '');
	}

	/** @return array{body: string, truncated: bool, bodyLength: int} */
	private function prepareCompactBody(array $row, bool $trimQuotes = true): array
	{
		$capped = (string)($row['BODY_CAPPED'] ?? '');
		$bodyLen = (int)($row['BODY_LEN'] ?? 0);
		// an expression field skips the Emoji fetch modificator declared on BODY_HTML, so stored
		// markers would otherwise reach the model as ":f09f9880:" text
		$htmlCapped = Emoji::decode((string)($row['HTML_CAPPED'] ?? ''));
		$htmlLen = (int)($row['HTML_LEN'] ?? 0);

		$sourceIsHtml = false;
		if (self::isCompactSourceEmpty($capped, $bodyLen))
		{
			$capped = $htmlCapped;
			$bodyLen = $htmlLen;
			$sourceIsHtml = true;
		}

		if (self::isCompactSourceEmpty($capped, $bodyLen))
		{
			return [
				'body' => '',
				'truncated' => false,
				'bodyLength' => 0,
			];
		}

		if ($sourceIsHtml || self::looksLikeHtml($capped))
		{
			$capped = self::htmlToText($capped);
		}

		$trimmed = $trimQuotes ? QuoteTrimmer::stripQuotedPlain($capped) : $capped;
		$quoteTailRemoved = ($trimmed !== $capped);

		$body = str_replace("\r\n", "\n", $trimmed);
		$body = trim($body);
		$body = preg_replace('/\n{3,}/', "\n\n", $body);

		$truncated = $bodyLen > self::COMPACT_BODY_LENGTH;
		if ($truncated)
		{
			$body .= $quoteTailRemoved
				? "\n[truncated: original body had {$bodyLen} characters, quoted tail removed]"
				: "\n[truncated: showing first " . self::COMPACT_BODY_LENGTH . " of {$bodyLen} characters]";
		}

		return [
			'body' => $body,
			'truncated' => $truncated,
			'bodyLength' => $bodyLen,
		];
	}

	private static function looksLikeHtml(string $body): bool
	{
		if ($body === '')
		{
			return false;
		}

		$hasOpeningTag = preg_match(
			'/<(?:html|head|body|div|p|br|hr|table|thead|tbody|tr|td|ul|ol|li|blockquote|span|a|img|font)[\s\/>]/i',
			$body,
		) === 1;
		if (!$hasOpeningTag)
		{
			return false;
		}

		// an opening tag alone is not enough: prose mentions lone tags ("use <p> and <br>")
		return preg_match(
			'/<\/(?:html|head|body|div|p|table|thead|tbody|tr|td|ul|ol|li|blockquote|span|a|font)\s*>/i',
			$body,
		) === 1
			|| preg_match('/<(?:!doctype|html|head|body)[\s>]/i', $body) === 1;
	}

	private static function htmlToText(string $html): string
	{
		if ($html === '')
		{
			return $html;
		}

		$charset = defined('SITE_CHARSET') && SITE_CHARSET !== '' ? SITE_CHARSET : 'UTF-8';

		return html_entity_decode(htmlToTxt($html, '', [], 0), ENT_QUOTES | ENT_HTML401, $charset);
	}

	private function sanitizeBody(array $row): string
	{
		$body = $row['BODY_HTML'] ?? $row['BODY'] ?? '';
		if ($body === '')
		{
			return '';
		}

		$body = Message::sanitizeHtmlForMessageView($body);
		$body = strip_tags($body);

		return trim($body);
	}

	private function formatBindings(array $row): array
	{
		$bindings = [];

		$crmOwnerId = (int)($row['CRM_ACTIVITY_OWNER_ID'] ?? 0);
		$crmOwnerTypeId = (int)($row['CRM_ACTIVITY_OWNER_TYPE_ID'] ?? 0);
		if ($crmOwnerId > 0 && $crmOwnerTypeId > 0)
		{
			$bindings[] = ['type' => 'crm', 'entityTypeId' => $crmOwnerTypeId, 'entityId' => $crmOwnerId];
		}

		$entityType = $row['BIND_ENTITY_TYPE'] ?? '';
		$entityId = (int)($row['BIND_ENTITY_ID'] ?? 0);
		if ($entityId > 0 && $entityType !== '')
		{
			$typeMap = [
				MessageAccessTable::ENTITY_TYPE_TASKS_TASK => 'task',
				MessageAccessTable::ENTITY_TYPE_IM_CHAT => 'chat',
				MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT => 'calendarEvent',
				MessageAccessTable::ENTITY_TYPE_BLOG_POST => 'blogPost',
			];

			$mappedType = $typeMap[$entityType] ?? null;
			if ($mappedType !== null)
			{
				$bindings[] = ['type' => $mappedType, 'entityId' => $entityId];
			}
		}

		return $bindings;
	}

	private function formatMessages(array $rows): array
	{
		$messages = [];

		foreach ($rows as $row)
		{
			$messageId = $row['MESSAGE_ID'] ?? $row['ID'];

			if (isset($messages[$messageId]))
			{
				$messages[$messageId]['bindings'] = $this->mergeBindings(
					$messages[$messageId]['bindings'],
					$this->formatBindings($row),
				);

				continue;
			}

			$messages[$messageId] = [
				'id' => (int)$messageId,
				'mailboxId' => (int)($row['MAILBOX_ID'] ?? 0),
				'mailboxEmail' => $row['MAILBOX_EMAIL'] ?? '',
				'subject' => $row['SUBJECT'] ?? '',
				'from' => $row['FIELD_FROM'] ?? '',
				'to' => $row['FIELD_TO'] ?? '',
				'date' => ($row['INTERNALDATE'] ?? $row['FIELD_DATE']) instanceof DateTime
					? ($row['INTERNALDATE'] ?? $row['FIELD_DATE'])->format('Y-m-d H:i:s')
					: (string)($row['INTERNALDATE'] ?? $row['FIELD_DATE'] ?? ''),
				'isSeen' => in_array($row['IS_SEEN'] ?? '', ['Y', 'S'], true),
				'url' => Message::getMessageUrl((int)$messageId),
				'hasAttachments' => !empty($row['ATTACHMENTS']),
				'bindings' => $this->formatBindings($row),
			];
		}

		return array_values($messages);
	}

	private function mergeBindings(array $current, array $new): array
	{
		$indexed = [];
		foreach (array_merge($current, $new) as $binding)
		{
			$key = implode(
				':',
				[
					$binding['type'] ?? '',
					(string)($binding['entityTypeId'] ?? ''),
					(string)($binding['entityId'] ?? ''),
				],
			);

			$indexed[$key] = $binding;
		}

		return array_values($indexed);
	}
}
