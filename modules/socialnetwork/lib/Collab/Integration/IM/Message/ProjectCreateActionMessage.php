<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Im\Bot;
use Bitrix\Im\User;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreate\ProjectAttachBuilder;
use Bitrix\Socialnetwork\Collab\Integration\Intranet\ServiceContainer;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use CAllSocNetUser;
use CUser;

class ProjectCreateActionMessage implements ActionMessageInterface
{
	use MessageTrait;

	public function __construct(
		protected int $collabId,
		protected int $senderId,
	)
	{
	}

	/**
	 * @throws LoaderException
	 * @throws ObjectNotFoundException
	 */
	public function send(array $recipientIds = [], array $parameters = []): int
	{
		if (!Loader::includeModule('im'))
		{
			return 0;
		}

		$project = $this->getWorkgroup($this->collabId);
		$message = $this->buildMessage($project, $this->resolveMembers($project, $parameters));

		$chat = Chat::getInstance($project->getChatId());
		$result = $chat->sendMessage($message);

		return $result->getMessageId() ?? 0;
	}

	/**
	 * The composition arrives as access codes, the same way the goal and the dates arrive, and is turned into
	 * words here: the card builder is handed names, it does not go looking for the members of the project.
	 *
	 * Names are plain, not user bb-codes: an attach value is shown as it is written, and the mobile client
	 * renders a [USER=id][/USER] with nothing inside as nothing at all
	 * (immobile/install/components/bitrix/immobile.attach/templates/.default/template.php).
	 */
	protected function resolveMembers(Workgroup $project, array $parameters): array
	{
		$ownerId = (int)($parameters['ownerId'] ?? 0);

		$mapper = Container::getInstance()->get(MemberEntityMapper::class);

		$memberIds = [];
		$structureAccessCodes = [];
		foreach (array_unique($parameters['members'] ?? []) as $accessCode)
		{
			$member = $mapper->fromAccessCode((string)$accessCode);
			if ($member?->id === null || $member->type === null)
			{
				continue;
			}

			if ($member->type === MemberEntityType::User)
			{
				if ($member->id !== $ownerId)
				{
					$memberIds[] = $member->id;
				}

				continue;
			}

			$structureAccessCodes[] = (string)$accessCode;
		}
		$nodes = $this->resolveStructureNodes($structureAccessCodes);

		$names = $this->resolveUserNames($project, [$ownerId, ...$memberIds]);

		if ($ownerId > 0)
		{
			$parameters['ownerName'] = $names[$ownerId] ?? '';
		}

		$parameters['memberNames'] = array_map(static fn(int $userId): string => $names[$userId] ?? '', $memberIds);
		$parameters['memberNodes'] = $nodes;

		return $parameters;
	}

	/**
	 * Everyone named on the card is resolved in one round: the number of people picked by name is limited
	 * neither by the wizard nor by the input, and the card is built inside the creation hit.
	 *
	 * A full name is printed only as far as the creator is allowed to see it - the rule the sibling message
	 * path follows (MessageTrait::getName()): a profile the creator may not view is named by its invitation
	 * name instead.
	 *
	 * @param int[] $userIds
	 * @return array<int, string> name by user id
	 */
	protected function resolveUserNames(Workgroup $project, array $userIds): array
	{
		$userIds = array_unique(array_filter($userIds, static fn(int $userId): bool => $userId > 0));
		if (empty($userIds))
		{
			return [];
		}

		$names = [];
		$hiddenIds = [];
		foreach ($this->getUserRows($userIds) as $userId => $user)
		{
			if ($this->isFullNameVisible($project, $user))
			{
				$names[$userId] = trim((string)User::formatFullNameFromDatabase($user));

				continue;
			}

			$hiddenIds[] = $userId;
		}

		if (empty($hiddenIds))
		{
			return $names;
		}

		foreach ($this->getInvitationNames($hiddenIds) as $userId => $name)
		{
			$names[(int)$userId] = trim((string)$name);
		}

		return $names;
	}

	/**
	 * Handlers of the OnGetProfileView event may read any field of a user, user fields included, so the whole
	 * row is taken - the way the calendar reads users for that same check.
	 *
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	protected function getUserRows(array $userIds): array
	{
		$rows = [];

		$cursor = CUser::getList('ID', 'ASC', ['ID' => implode('|', $userIds)], ['SELECT' => ['UF_*']]);
		while ($user = $cursor->fetch())
		{
			$rows[(int)$user['ID']] = $user;
		}

		return $rows;
	}

	protected function isFullNameVisible(Workgroup $project, array $user): bool
	{
		if ((int)$user['ID'] === $this->senderId || ($user['EXTERNAL_AUTH_ID'] ?? '') === Bot::EXTERNAL_AUTH_ID)
		{
			return true;
		}

		foreach ($project->getSiteIds() as $siteId)
		{
			if (CAllSocNetUser::CanProfileView($this->senderId, $user, $siteId))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, string>
	 */
	protected function getInvitationNames(array $userIds): array
	{
		return ServiceContainer::getInstance()?->getUserService()?->getFormattedInvitationNameByIds($userIds) ?? [];
	}

	/**
	 * The name of a department or a team comes from the org structure by the very access code the project was
	 * given, so a legacy department and a team node are found the same way.
	 */
	protected function resolveStructureNodes(array $accessCodes): array
	{
		return Container::getInstance()
			->getStructureRelationService()
			->getVisibleNodesByAccessCodes($accessCodes, $this->senderId)
			->getEntities()
		;
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function buildMessage(Workgroup $project, array $parameters): Message
	{
		$message = (new Message())
			->setAuthorId($this->senderId)
			->setChatId($project->getChatId())
			->setMessage($this->buildMessageText())
			->markAsSystem(true)
		;

		$attach = $this->getAttachBuilder()->build($project, $this->senderId, $parameters);
		$message->getAttach()->addValue($attach);

		return $message;
	}

	private function getAttachBuilder(): ProjectAttachBuilder
	{
		return Container::getInstance()->getProjectAttachBuilder();
	}

	private function buildMessageText(): string
	{
		return (string)Loc::getMessage(
			'SOCIALNETWORK_CHAT_PROJECT_CREATE' . $this->getGenderSuffix($this->senderId),
			['#SENDER_NAME#' => "[USER={$this->senderId}][/USER]"],
		);
	}
}
