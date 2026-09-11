<?php

namespace Bitrix\Sign\Service\Integration\Socialnetwork;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;
use Bitrix\Sign\Repository\SignersList\SignersListUserRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Socialnetwork\ComponentHelper;
use Bitrix\Socialnetwork\Integration\Intranet\Settings;

/**
 * Publication of a post in the activity stream from the employee groups screens: whether the
 * feed can take a post at all and who of the group will receive it.
 *
 * The post itself is composed and published by the feed, this module only prepares the
 * recipients for its form.
 */
class FeedPostService
{
	/**
	 * Upper bound of a recipient set. A larger group does not fit a post: the answer of the
	 * action grows into hundreds of kilobytes and the recipient selector of the form gets a tag
	 * per employee. The value is deliberately far above any real group of employees.
	 */
	public const MAX_RECIPIENT_COUNT = 1000;

	private const USER_CHUNK_SIZE = 500;
	private const FEED_TOOL_ID = 'news';
	private const PRESELECTED_RECIPIENTS_MARKER = 'isPostFormPreselectedRecipientsSupported';
	private const RECIPIENT_CODE_PREFIX = 'U';

	private readonly SignersListUserRepository $signersListUserRepository;

	public function __construct(?SignersListUserRepository $signersListUserRepository = null)
	{
		$this->signersListUserRepository =
			$signersListUserRepository ?? Container::instance()->getSignersListUserRepository()
		;
	}

	/**
	 * The modules are released independently, so the post form of this portal accepts a chosen
	 * recipient set only when the feed carries the marker of that support. Without it the
	 * feature stays off instead of opening a form that would post to everybody.
	 *
	 * A post of the feed is a blog post, so without that module there is nothing to publish
	 * into: the availability of the feed tool alone does not answer for it.
	 */
	public function isPostAvailable(): bool
	{
		if (!Loader::includeModule('socialnetwork') || !Loader::includeModule('blog'))
		{
			return false;
		}

		if (!$this->isPreselectedRecipientsSupported())
		{
			return false;
		}

		return (new Settings())->isToolAvailable(self::FEED_TOOL_ID);
	}

	/**
	 * Recipient codes of the group, or null when the group does not fit a post: a group larger
	 * than a post can take is refused as a whole instead of being cut, since a truncated set
	 * would let the author publish a post that silently reached only a part of the group.
	 *
	 * @return list<string>|null
	 */
	public function getRecipientCodesForList(int $listId): ?array
	{
		// one identifier over the limit is all it takes to know the group does not fit
		$userIds = $this->signersListUserRepository->listUserIds($listId, self::MAX_RECIPIENT_COUNT + 1);

		if (count($userIds) > self::MAX_RECIPIENT_COUNT)
		{
			return null;
		}

		return $this->getRecipientCodes($userIds);
	}

	/**
	 * Recipient codes of the employees who have access to the portal: an active internal
	 * account with a confirmed invitation. External, email and technical accounts cannot read
	 * the feed, so they are not offered as recipients. An empty result is a regular outcome.
	 *
	 * @param list<int> $userIds
	 * @return list<string>
	 */
	public function getRecipientCodes(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));

		if ($userIds === [])
		{
			return [];
		}

		$recipients = [];
		foreach (array_chunk($userIds, self::USER_CHUNK_SIZE) as $chunk)
		{
			$rows = UserTable::query()
				->setSelect(['ID'])
				->whereIn('ID', $chunk)
				->where('ACTIVE', 'Y')
				->where('IS_REAL_USER', 'Y')
				->where('UF_DEPARTMENT', '!=', false)
				->where(Query::filter()
					->logic('or')
					->where('CONFIRM_CODE', '')
					->whereNull('CONFIRM_CODE')
				)
				->exec()
			;

			while ($row = $rows->fetch())
			{
				$recipients[] = self::RECIPIENT_CODE_PREFIX . $row['ID'];
			}
		}

		return $recipients;
	}

	protected function isPreselectedRecipientsSupported(): bool
	{
		return class_exists(ComponentHelper::class)
			&& method_exists(ComponentHelper::class, self::PRESELECTED_RECIPIENTS_MARKER)
		;
	}
}
