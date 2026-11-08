<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Helper\Message\Loader\MessageFilter;
use Bitrix\Mail\Helper\Message\Loader\MessageLoader;
use Bitrix\Mail\Internal\Service\Draft\DraftService;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\MessageView\AvatarManager;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageService;
use Bitrix\Main\Error;
use Bitrix\Main\Grid\Options as GridOptions;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\PageNavigation;

/**
 * A page of the mail list as records rather than markup. The screen asks for its first page while it
 * renders, the controller asks for every page after it, and both get the same record.
 *
 * Selection stays in MessageLoader, so the source generation limit keeps living in its query builder
 * and this service adds no query of its own.
 */
class MessageListService
{
	public const ERROR_MAILBOX_NOT_FOUND = 'MAILBOX_NOT_FOUND';

	/**
	 * The last page the list answers for. The page number becomes the offset of a selection over the
	 * busiest table of the module, so unbounded it lets a caller make the database read and throw away
	 * as many rows as it names. The list grows page by page, and the browser gives out on the rows it
	 * holds long before two hundred of them.
	 */
	public const MAX_PAGE = 200;

	/** The widest page the list answers with; above every size the product grids offer. */
	public const MAX_PAGE_SIZE = 100;

	private const MESSAGE_NAVIGATION_ID = 'mail-message-list';
	private const DRAFT_NAVIGATION_ID = 'mail-internal-draft-list';
	private const DEFAULT_PAGE_SIZE = 25;
	private const DRAFT_MAX_PAGE_SIZE = 50;

	public function __construct(
		private readonly FilterStateReader $filterStateReader = new FilterStateReader(),
		private readonly UndoSendProjectionPolicy $undoSendPolicy = new UndoSendProjectionPolicy(),
		private readonly int $maxPage = self::MAX_PAGE,
		private readonly int $maxPageSize = self::MAX_PAGE_SIZE,
	)
	{
	}

	public function getMaxPage(): int
	{
		return $this->maxPage;
	}

	/**
	 * @return Result Data: rows, projections, enableNextPage, pageLimitReached, page, hrefIds,
	 *                filterApplied
	 */
	public function getPage(ScreenContext $context): Result
	{
		$result = new Result();

		$mailboxHelper = Mailbox::createInstance($context->mailboxId, false);
		if (!($mailboxHelper instanceof Mailbox))
		{
			return $result->addError(new Error('Mailbox not found.', self::ERROR_MAILBOX_NOT_FOUND));
		}

		$navigation = $this->createNavigation(
			self::MESSAGE_NAVIGATION_ID,
			$this->resolvePageSize($context->gridId),
			$context->page,
		);
		$dirsHelper = $mailboxHelper->getDirsHelper();
		$filterState = $this->filterStateReader->read($context, $dirsHelper);

		$items = MessageLoader::getMessageList(
			new MessageFilter(
				$context->getMailboxIdsForFilter(),
				$filterState->filter,
				true,
				$context->listImprovementsEnabled ? $context->userId : null,
				withAttachmentsStack: $context->listImprovementsEnabled,
			),
			$navigation,
		);

		return $result->setData(
			$this->buildMessagePage($items, $navigation, $context, $filterState, $dirsHelper),
		);
	}

	/**
	 * @return Result Data: rows, enableNextPage, pageLimitReached, page, total
	 */
	public function getDraftPage(ScreenContext $context): Result
	{
		$pageSize = min(self::DRAFT_MAX_PAGE_SIZE, $this->resolvePageSize($context->gridId));
		$navigation = $this->createNavigation(self::DRAFT_NAVIGATION_ID, $pageSize, $context->page);
		$criteria = $this->filterStateReader->readDraftCriteria($context);

		$page = (new DraftService())->list(
			userId: $context->userId,
			page: $navigation->getCurrentPage(),
			pageSize: $navigation->getPageSize(),
			search: $criteria->search,
			recipient: $criteria->recipient,
			hasAttachments: $criteria->hasAttachments,
		)->getData()['page'] ?? [];

		$total = (int)($page['total'] ?? 0);
		$builder = new DraftRowBuilder();
		$rows = [];

		foreach ((array)($page['items'] ?? []) as $item)
		{
			$row = $builder->build((array)$item);
			if ($row !== null)
			{
				$rows[] = $row;
			}
		}

		$hasMore = $navigation->getCurrentPage() * $navigation->getPageSize() < $total;
		$withinCeiling = $this->isWithinPageCeiling($navigation);

		return (new Result())->setData([
			'rows' => $rows,
			'enableNextPage' => $hasMore && $withinCeiling,
			'pageLimitReached' => $hasMore && !$withinCeiling,
			'page' => $navigation->getCurrentPage(),
			'total' => $total,
		]);
	}

	private function buildMessagePage(
		array $items,
		PageNavigation $navigation,
		ScreenContext $context,
		FilterState $filterState,
		MailboxDirectoryHelper $dirsHelper,
	): array
	{
		$pageItems = $this->getVisiblePageItems($items, $navigation);
		$builder = new MessageRowBuilder(
			context: $context,
			currentDir: $filterState->currentDir,
			avatarConfigs: $this->loadAvatarConfigs($pageItems, $context->userId),
			dateLastOpening: $this->readDateLastOpening($context->mailboxId),
		);

		$rows = [];
		$hrefIds = [];
		$hasMore = false;
		$limit = $navigation->getLimit();
		// one record for the whole page: it depends on the folder of the list, not on the letter
		$sliderOptions = $builder->buildSliderOptions();

		foreach ($items as $item)
		{
			// hrefIds is one element longer than rows when a next page follows: the slider steps to the
			// next message by that extra id
			$hrefIds[] = (int)$item['MESSAGE_ID'];

			if (count($rows) >= $limit)
			{
				$hasMore = true;

				break;
			}

			$rows[] = $builder->build(
				$item,
				DirectoryFlags::fromDirectory($dirsHelper->getDirByHash($item['DIR_MD5'] ?? '')),
				$sliderOptions,
			);
		}

		$withinCeiling = $this->isWithinPageCeiling($navigation);

		return [
			'rows' => $rows,
			'projections' => $this->buildUndoSendProjections($context, $filterState, $dirsHelper, $navigation),
			'enableNextPage' => $hasMore && $withinCeiling,
			'pageLimitReached' => $hasMore && !$withinCeiling,
			'page' => $navigation->getCurrentPage(),
			'hrefIds' => $hrefIds,
			// an empty list says one word about a folder out of letters and another about a search that
			// found none; the screen cannot say which on behalf of the pages that follow it
			'filterApplied' => $filterState->applied,
		];
	}

	/**
	 * Letters on their way out that can still be called back. They stand in front of the whole list,
	 * not inside a page of it, so they come with every reload of the first page - that is what keeps
	 * the screen asking for the list until the letter has left.
	 *
	 * Read through the service rather than the repository: a mailbox may be shared, and only the
	 * service asks whether this reader may see what is queued in it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildUndoSendProjections(
		ScreenContext $context,
		FilterState $filterState,
		MailboxDirectoryHelper $dirsHelper,
		PageNavigation $navigation,
	): array
	{
		if (!$this->undoSendPolicy->isOffered($context, $filterState, $dirsHelper, $navigation->getCurrentPage()))
		{
			return [];
		}

		$result = (new ScheduledMessageService())->getUndoProjections($context->userId, $context->mailboxId);
		if (!$result->isSuccess())
		{
			return [];
		}

		return (new UndoSendProjectionBuilder())->build((array)($result->getData()['items'] ?? []));
	}

	private function createNavigation(string $navigationId, int $pageSize, int $page): PageNavigation
	{
		$navigation = new PageNavigation($navigationId);
		$navigation->setPageSize($pageSize)->setCurrentPage(max(1, $page));

		return $navigation;
	}

	/**
	 * Only the ceiling, not the end of the list: the two ends are not alike, a folder out of letters
	 * ends in silence and a list out of pages still has letters behind it. Hence the caller builds
	 * both enableNextPage and pageLimitReached out of this answer and what the page brought.
	 */
	private function isWithinPageCeiling(PageNavigation $navigation): bool
	{
		return $navigation->getCurrentPage() < $this->maxPage;
	}

	/**
	 * The page size is a user setting written by a platform endpoint that accepts any grid and any
	 * number (main/lib/grid/options.php:87). Unclamped it undoes the page ceiling: the cost of a
	 * request is the offset, and the offset is the size times the page number.
	 */
	private function resolvePageSize(string $gridId): int
	{
		$navParams = (new GridOptions($gridId))->getNavParams(['nPageSize' => self::DEFAULT_PAGE_SIZE]);

		return min($this->maxPageSize, max(1, (int)$navParams['nPageSize']));
	}

	private function loadAvatarConfigs(array $pageItems, int $userId): array
	{
		$messages = [];
		foreach ($pageItems as $item)
		{
			$messages[] = [
				'ID' => (int)($item['MESSAGE_ID'] ?? 0),
				'MAILBOX_ID' => (int)($item['MAILBOX_ID'] ?? 0),
				'__email' => (string)($item['MAILBOX_EMAIL'] ?? ''),
				'__access_level' => 1,
				'FIELD_FROM' => (string)($item['FIELD_FROM'] ?? ''),
				'FIELD_TO' => (string)($item['FIELD_TO'] ?? ''),
				'FIELD_CC' => (string)($item['FIELD_CC'] ?? ''),
				'FIELD_BCC' => (string)($item['FIELD_BCC'] ?? ''),
			];
		}

		return $this->createAvatarManager($userId)->getAvatarParamsFromMessagesHeaders($messages);
	}

	private function getVisiblePageItems(array $items, PageNavigation $navigation): array
	{
		return array_slice($items, 0, $navigation->getLimit());
	}

	protected function createAvatarManager(int $userId): AvatarManager
	{
		return new AvatarManager($userId);
	}

	/** The screen rewrites this stamp on every render, so rows compare against the previous opening. */
	private function readDateLastOpening(int $mailboxId): int
	{
		$row = MailEntityOptionsTable::getList([
			'select' => ['DATE_INSERT'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ENTITY_TYPE' => 'MAILBOX',
				'=ENTITY_ID' => $mailboxId,
				'=PROPERTY_NAME' => 'LAST_MAIL_OPENING',
			],
		])->fetch();

		return (int)makeTimeStamp($row['DATE_INSERT'] ?? new DateTime());
	}
}
