<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Access;

use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\Im\Bot;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserFactory;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

/**
 * The only source of the rule 'who carries the open contexts access code'. Both readers answer the
 * same question: isCarrier() one user at a time, nonCarriersOf() in batch for the permission
 * builder, which may not walk the relations of a general chat user by user on every message.
 */
class CodeCarrierResolver
{
	private const MEMBERSHIP_PORTION_OPTION = 'message_search_membership_portion';
	private const DEFAULT_MEMBERSHIP_PORTION = 500;

	/** @var array<int, array<int, int>> non-carriers of a chat as userId => startId */
	private array $nonCarriersByChatId = [];

	public function isCarrier(int $userId): bool
	{
		$user = User::getInstance($userId);

		// isExtranet() alone answers this on a complete portal; guest and collaber are spelled out
		// because it degrades to false where intranet or humanresources is missing
		return !$user->isExtranet() && !$user->isGuest() && !$user->isCollaber();
	}

	/**
	 * @return array<int, int> Members that carry no access code of their own, as userId => startId.
	 */
	public function nonCarriersOf(Chat $chat): array
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0)
		{
			return [];
		}

		return $this->nonCarriersByChatId[$chatId] ??= $this->resolveNonCarriers($chatId);
	}

	/**
	 * Both maps that describe the membership of a chat are dropped through PermissionBuilder, which
	 * owns the contract of invalidation: a non-carrier left over here is named on every record of an
	 * open context written afterwards, and that is an overshow.
	 */
	public function forgetChat(int $chatId): void
	{
		unset($this->nonCarriersByChatId[$chatId]);
	}

	public function forgetAllChats(): void
	{
		$this->nonCarriersByChatId = [];
	}

	/**
	 * The membership is walked by keyset portions and never read whole: the answer is asked for while a
	 * message of an open channel is being sent, and the membership of a channel is the whole portal. Of
	 * a portion only the non-carriers are kept, and those are the few the shared code leaves out.
	 *
	 * @return array<int, int>
	 */
	private function resolveNonCarriers(int $chatId): array
	{
		$isStructureQueryable = $this->isStructureQueryable();
		$portion = self::getMembershipPortion();
		$nonCarriers = [];
		$cursor = 0;

		do
		{
			$members = self::readMembershipPortion($chatId, $cursor, $portion);
			if ($members === [])
			{
				break;
			}

			$cursor = (int)$members[array_key_last($members)]['USER_ID'];
			$candidates = self::keepHumans($members);
			if ($candidates !== [])
			{
				$nonCarriers += $this->selectNonCarriers($candidates, $isStructureQueryable);
			}
		}
		while (count($members) === $portion);

		return $nonCarriers;
	}

	/**
	 * @param array<int, int> $candidates userId => startId
	 * @return array<int, int> userId => startId
	 */
	private function selectNonCarriers(array $candidates, bool $isStructureQueryable): array
	{
		if ($isStructureQueryable)
		{
			$employees = Container::getUserDepartmentService()->filterEmployeeIds(array_keys($candidates));

			return array_diff_key($candidates, array_flip($employees));
		}

		UserFactory::getInstance()->getUsersByIds(array_keys($candidates));

		return array_filter(
			$candidates,
			fn (int $userId): bool => !$this->isCarrier($userId),
			ARRAY_FILTER_USE_KEY,
		);
	}

	/**
	 * @return array<int, array> Rows of the membership ascending by USER_ID, the cursor of the walk.
	 */
	private static function readMembershipPortion(int $chatId, int $cursor, int $limit): array
	{
		return RelationTable::query()
			->setSelect(['USER_ID', 'START_ID', 'EXTERNAL_AUTH_ID' => 'USER.EXTERNAL_AUTH_ID'])
			->where('CHAT_ID', $chatId)
			->where('USER_ID', '>', $cursor)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;
	}

	/**
	 * Members of the portion except bots, which carry the code themselves.
	 *
	 * @param array<int, array> $members
	 * @return array<int, int> userId => startId
	 */
	private static function keepHumans(array $members): array
	{
		$candidates = [];
		foreach ($members as $member)
		{
			if ($member['EXTERNAL_AUTH_ID'] === Bot::EXTERNAL_AUTH_ID)
			{
				continue;
			}

			$candidates[(int)$member['USER_ID']] = (int)$member['START_ID'];
		}

		return $candidates;
	}

	private static function getMembershipPortion(): int
	{
		return max(1, (int)Option::get('im', self::MEMBERSHIP_PORTION_OPTION, self::DEFAULT_MEMBERSHIP_PORTION));
	}

	/**
	 * Tells whether employees can be told apart in one query. Without a converted company structure the
	 * per-user predicate falls back to UF_DEPARTMENT, and without intranet it calls everyone a carrier;
	 * neither answer may be reached in batch, so both send the resolver into the per-user branch.
	 */
	private function isStructureQueryable(): bool
	{
		return ModuleManager::isModuleInstalled('intranet')
			&& Loader::includeModule('humanresources')
			&& Storage::instance()->isCompanyStructureConverted()
		;
	}
}
