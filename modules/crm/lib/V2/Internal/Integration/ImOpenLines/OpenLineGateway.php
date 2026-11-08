<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\ImOpenLines;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\OpenLineItem;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\OpenLineItemCollection;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSort;
use Bitrix\Crm\V2\Internal\Exception\WebForm\OpenLineAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\OpenLinesNotInstalledException;
use Bitrix\ImOpenLines\Config;
use Bitrix\ImOpenLines\Security\Permissions;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;

/**
 * The single point CRM V2 asks the open lines module about the chats a CRM form can greet visitors in.
 *
 * The module is an optional dependency of a portal, and its absence is a different answer from a
 * refusal of access: the two leave by different exceptions and the caller tells them apart.
 *
 * The permission asked for is the one to modify a chat, not to view it: the list exists to pick the
 * chat a welcome form is placed in, and a chat the user may not manage is no option for them.
 *
 * @internal
 */
class OpenLineGateway
{
	private const MAX_PAGE_SIZE = 1000;

	/**
	 * Returns a stable page of the active chats the user may modify, projected against the form.
	 *
	 * @throws OpenLinesNotInstalledException the portal has no open lines module
	 * @throws OpenLineAccessDeniedException the user may not modify open line chats
	 * @throws ArgumentException the page is out of bounds
	 */
	public function findAll(
		int $userId,
		int $formId,
		WebFormSort $sort,
		int $limit,
		int $offset,
	): OpenLineItemCollection
	{
		if ($limit < 1 || $limit > self::MAX_PAGE_SIZE)
		{
			throw new ArgumentException(sprintf('Invalid open line page limit `%d`', $limit), 'limit');
		}
		if ($offset < 0)
		{
			throw new ArgumentException(sprintf('Invalid open line page offset `%d`', $offset), 'offset');
		}

		$this->assertCanModifyChats($userId);

		$items = [];
		foreach (array_slice($this->fetchChats($userId, $sort), $offset, $limit) as $row)
		{
			$items[] = self::readItem($row, $formId);
		}

		return new OpenLineItemCollection(...$items);
	}

	/**
	 * Refuses the caller the module or the permission every chat list stands on.
	 *
	 * A scenario asks this before it reads the form it was given: what a user may not do at all is
	 * settled before whether the form they named exists.
	 *
	 * @throws OpenLinesNotInstalledException the portal has no open lines module
	 * @throws OpenLineAccessDeniedException the user may not modify open line chats
	 */
	public function assertCanModifyChats(int $userId): void
	{
		if (!$this->isModuleAvailable())
		{
			throw new OpenLinesNotInstalledException();
		}
		if (!$this->hasModifyPermission($userId))
		{
			throw new OpenLineAccessDeniedException();
		}
	}

	protected function isModuleAvailable(): bool
	{
		return Loader::includeModule('imopenlines');
	}

	protected function hasModifyPermission(int $userId): bool
	{
		return Permissions::createWithUserId($userId)->canModifyLines();
	}

	/**
	 * Returns every chat the user may modify, in the order of the page.
	 *
	 * No limit is asked for: the module applies the permission to the rows it has already read, so a
	 * limited query would answer with a page full of holes. The page is cut off the whole selection
	 * instead, and its size stays bounded by the number of chats a portal is licensed to have.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetchChats(int $userId, WebFormSort $sort): array
	{
		return (new Config($userId))->getList(
			[
				'select' => [
					'ID',
					'LINE_NAME',
					'USE_WELCOME_FORM',
					'WELCOME_FORM_ID',
					'WELCOME_FORM_DELAY',
					// the module filters the rows by this field and reads it off the selected row
					'MODIFY_USER_ID',
				],
				'filter' => [
					'=ACTIVE' => 'Y',
					// a temporary chat serves an unfinished setup of its own and outlives it by hours
					'=TEMPORARY' => 'N',
				],
				'order' => ['ID' => $sort->getIdDirection()],
			],
			['CHECK_PERMISSION' => Permissions::ACTION_MODIFY],
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function readItem(array $row, int $formId): OpenLineItem
	{
		$welcomeFormEnabled = self::isFlagSet($row['USE_WELCOME_FORM'] ?? null);
		$welcomeFormId = (int)($row['WELCOME_FORM_ID'] ?? 0);

		return new OpenLineItem(
			id: (int)($row['ID'] ?? 0),
			name: (string)($row['LINE_NAME'] ?? ''),
			bound: $welcomeFormEnabled && $welcomeFormId === $formId,
			welcomeFormEnabled: $welcomeFormEnabled,
			welcomeFormId: $welcomeFormId > 0 ? $welcomeFormId : null,
			welcomeFormDelayed: self::isFlagSet($row['WELCOME_FORM_DELAY'] ?? null),
		);
	}

	private static function isFlagSet(mixed $value): bool
	{
		return $value === true || $value === 'Y' || $value === 1 || $value === '1';
	}
}
