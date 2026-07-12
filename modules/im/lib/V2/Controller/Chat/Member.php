<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Entity\User\UserShortPopupItem;
use Bitrix\Im\V2\Relation\Provider\RelationCursor;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Main\Validation\Engine\AutoWire\ValidationParameter;

class Member extends BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge(
			[
				new ValidationParameter(
					RelationCursor::class,
					fn ($className, array $cursor) => RelationCursor::createFromArray($cursor),
					fn () => 'cursor'
				),
			],
			parent::getAutoWiredParameters()
		);
	}

	/**
	 * @restMethod im.v2.Chat.Member.tail
	 */
	public function tailAction(Chat $chat, int $limit = 50, ?RelationCursor $relationCursor = null): ?array
	{
		$members = $chat->getRelationProvider()->getMembers($limit, $relationCursor);
		$nextCursor = RelationCursor::getNext($members, $limit);
		$rest = $this->toRestFormat($members);
		$rest['nextCursor'] = $nextCursor?->toRestFormat();

		return $rest;
	}

	/**
	 * @restMethod im.v2.Chat.Member.filterUsersByParticipation
	 */
	public function filterUsersByParticipationAction(Chat $chat, array $userIds = []): ?array
	{
		$userIds = array_map('intval', $userIds);

		$relations = $chat->getRelationsByUserIds($userIds)->filterActiveMembers();

		return (new RestAdapter($relations))->toRestFormat(['POPUP_DATA_EXCLUDE' => [UserPopupItem::class]]);
	}

	/**
	 * @restMethod im.v2.Chat.Member.checkMembership
	 */
	public function checkMembershipAction(Chat $chat, array $userIds = []): ?array
	{
		$userIds = Normalizer::toUniquePositiveIntegers($userIds);

		$usersInChat = $chat->getRelationsByUserIds($userIds)->filterActiveMembers()->getUserIds();
		$usersNotInChat = array_diff($userIds, $usersInChat);

		return [
			'usersInChat' => array_values($usersInChat),
			'usersNotInChat' => array_values($usersNotInChat),
			'users' => (new UserShortPopupItem($userIds))->toRestFormat(),
		];
	}
}
