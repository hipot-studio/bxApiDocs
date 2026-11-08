<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Intranet;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Label\LabelsFeature;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\MessageFolder;
use Bitrix\Mail\Internal\Service\MessageList\MessageListService;
use Bitrix\Mail\Internal\Service\MessageList\ScreenContext;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Request;
use Bitrix\Main\Result;

/**
 * Pages of the mail list after the first one, which the screen renders itself.
 *
 * Nothing about access arrives with the request: the mailbox is looked up among the ones the user
 * actually has, the plan is asked again, and the row address templates are module defaults.
 */
final class MessageList extends Controller
{
	public const ERROR_ACCESS_DENIED = 'MAILBOX_ACCESS_DENIED';
	public const ERROR_SYNC_UNAVAILABLE = 'MAILBOX_SYNC_UNAVAILABLE';
	public const ERROR_DRAFTS_UNAVAILABLE = 'INTERNAL_DRAFTS_UNAVAILABLE';
	public const ERROR_GRID_UNAVAILABLE = 'MESSAGE_GRID_UNAVAILABLE';
	public const ERROR_INVALID_SCREEN = 'INVALID_SCREEN';
	public const ERROR_PAGE_OUT_OF_RANGE = 'PAGE_OUT_OF_RANGE';

	public function __construct(
		?Request $request = null,
		private readonly MessageListService $service = new MessageListService(),
	)
	{
		parent::__construct($request);
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = [
			new ActionFilter\ContentType([
				ActionFilter\ContentType::JSON,
				'application/x-www-form-urlencoded',
			]),
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];

		if (Loader::includeModule('intranet'))
		{
			$filters[] = new Intranet\ActionFilter\IntranetUser();
		}

		return $filters;
	}

	public function getPageAction(
		string $gridId,
		string $filterId,
		int $mailboxId,
		int $page = 1,
		?string $virtual = null,
		?string $listSection = null,
	): ?array
	{
		if (
			!$this->hasScreenIdentifiers($gridId, $filterId)
			|| !$this->isPageWithinReach($page)
			|| !$this->isGridAvailable()
		)
		{
			return null;
		}

		$mailboxes = MailboxTable::getUserMailboxes($this->getUserId());
		$mailbox = $this->resolveMailbox($mailboxes, $mailboxId);
		if ($mailbox === null)
		{
			return null;
		}

		$isAllMailMode = $virtual === MessageFolder::VIRTUAL_ALL_MESSAGES;
		if (!$this->isFetchingAvailable($mailbox, $isAllMailMode ? $mailboxes : []))
		{
			$this->deny(self::ERROR_SYNC_UNAVAILABLE, 'Fetching messages is not available.');

			return null;
		}

		return $this->respond($this->service->getPage(new ScreenContext(
			userId: $this->getUserId(),
			mailboxId: (int)$mailbox['ID'],
			gridId: $gridId,
			filterId: $filterId,
			mailboxEmail: (string)($mailbox['EMAIL'] ?? ''),
			page: $page,
			mailboxIds: array_map('intval', array_keys($mailboxes)),
			isAllMailMode: $isAllMailMode,
			listSection: $listSection,
			listImprovementsEnabled: Feature::isMailListImprovementsAvailable(),
			labelsEnabled: LabelsFeature::isEnabled(),
			crmActivityPermission: MailboxAccess::hasCurrentUserAccessToViewMailboxIntegrationCrm(),
		)));
	}

	public function getDraftPageAction(
		string $gridId,
		string $filterId,
		int $mailboxId,
		int $page = 1,
	): ?array
	{
		if (
			!$this->hasScreenIdentifiers($gridId, $filterId)
			|| !$this->isPageWithinReach($page)
			|| !$this->isGridAvailable()
		)
		{
			return null;
		}

		if (!Feature::isInternalDraftsWebAvailable())
		{
			$this->deny(self::ERROR_DRAFTS_UNAVAILABLE, 'Internal drafts are not available.');

			return null;
		}

		$mailbox = $this->resolveMailbox(MailboxTable::getUserMailboxes($this->getUserId()), $mailboxId);
		if ($mailbox === null)
		{
			return null;
		}

		return $this->respond($this->service->getDraftPage(new ScreenContext(
			userId: $this->getUserId(),
			mailboxId: (int)$mailbox['ID'],
			gridId: $gridId,
			filterId: $filterId,
			page: $page,
		)));
	}

	/** Both ids name user settings - the page size and the saved filter - and an empty one names none. */
	private function hasScreenIdentifiers(string $gridId, string $filterId): bool
	{
		if ($gridId === '' || $filterId === '')
		{
			$this->addError(new Error('Screen identifiers are required.', self::ERROR_INVALID_SCREEN));

			return false;
		}

		return true;
	}

	/**
	 * Refused past the ceiling, never on it: the last page is answered as any other and promises
	 * nothing after itself (MessageListService::isWithinPageCeiling() decides that), so a reader who
	 * walked there by the button never sees an error. Only a page nobody could have walked to is refused.
	 */
	private function isPageWithinReach(int $page): bool
	{
		if ($page > $this->service->getMaxPage())
		{
			$this->addError(new Error('The page is out of the list.', self::ERROR_PAGE_OUT_OF_RANGE));

			return false;
		}

		return true;
	}

	/**
	 * The option that switches the screen to the new grid has to close the answers of the list as well
	 * as its screen.
	 *
	 * Checked after the two refusals in front of it, so a request naming a page out of the list keeps
	 * hearing about the page it named.
	 */
	private function isGridAvailable(): bool
	{
		if (!Feature::isMessageGridVueAvailable())
		{
			$this->deny(self::ERROR_GRID_UNAVAILABLE, 'The message grid is not available.');

			return false;
		}

		return true;
	}

	/** The user list holds owned and shared mailboxes alike, so anything absent from it is denied. */
	private function resolveMailbox(array $mailboxes, int $mailboxId): ?array
	{
		$mailbox = $mailboxes[$mailboxId] ?? null;
		if (!is_array($mailbox))
		{
			$this->deny(self::ERROR_ACCESS_DENIED, 'Access to the mailbox is denied.');

			return null;
		}

		return $mailbox;
	}

	/**
	 * The screen asks the plan the same question before it selects its own page; asking again here
	 * keeps a request for the next page from stepping around the restriction.
	 *
	 * @param array $fallbackMailboxes Mailboxes the all mail mode falls back on; empty in every other
	 *                                 mode.
	 */
	private function isFetchingAvailable(array $mailbox, array $fallbackMailboxes): bool
	{
		if (LicenseManager::checkTheMailboxForSyncAvailability((int)$mailbox['ID'], (int)$mailbox['USER_ID']))
		{
			return true;
		}

		foreach ($fallbackMailboxes as $item)
		{
			if (LicenseManager::checkTheMailboxForSyncAvailability((int)$item['ID'], (int)$item['USER_ID']))
			{
				return true;
			}
		}

		return false;
	}

	private function respond(Result $result): ?array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	private function deny(string $code, string $message): void
	{
		Context::getCurrent()->getResponse()->setStatus(403);
		$this->addError(new Error($message, $code));
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
