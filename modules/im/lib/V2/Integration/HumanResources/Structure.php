<?php

namespace Bitrix\Im\V2\Integration\HumanResources;

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\RelationEntitySubtype;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\EntitySelector\Converter;

class Structure
{
	private const SUPPORTED_ENTITY_TYPES = ['user', 'department', 'structure-node'];

	/**
	 * The chat types the linking is offered for. The list repeats the rows of the rights matrix that
	 * carry `Action::LinkStructureNode`: a type without such a row falls back to the guest role
	 * (`Permission::getRoleForActionByType()`), so the prefilter lets any member of it through and
	 * the type has to be answered by the method itself.
	 */
	private const SUPPORTED_CHAT_TYPES = [
		Chat\ExtendedType::Chat->value,
		Chat\ExtendedType::OpenChat->value,
		Chat\ExtendedType::Channel->value,
		Chat\ExtendedType::OpenChannel->value,
	];

	/** A trailing `:F` means "this node only", so the id is not always a number */
	private const ENTITY_ID_PATTERN = '/^\d+(:F)?$/';

	/** Hard limit of NodeRelationService::getUsersNotInRelation(), not a tuning knob */
	private const USERS_PER_STRUCTURE_QUESTION = 500;

	protected Chat $chat;

	public function __construct(Chat $chat)
	{
		$this->chat = $chat;
	}

	public static function isTeamsAvailable(): bool
	{
		return Loader::includeModule('humanresources')
			&& Storage::instance()->isCompanyStructureConverted()
			&& Feature::instance()->isCrossFunctionalTeamsAvailable()
		;
	}

	/**
	 * Must be called before splitEntities(): Converter::convertToFinderCodes() destructures every
	 * element into a pair and matches the id with preg_match, so a non-scalar id ends in a TypeError
	 * instead of a contract error.
	 */
	public static function isValidEntityList(array $entities): bool
	{
		foreach ($entities as $entity)
		{
			if (!is_array($entity) || count($entity) !== 2 || !isset($entity[0], $entity[1]))
			{
				return false;
			}

			if (!in_array($entity[0], self::SUPPORTED_ENTITY_TYPES, true))
			{
				return false;
			}

			if (!is_int($entity[1]) && !is_string($entity[1]))
			{
				return false;
			}

			if (preg_match(self::ENTITY_ID_PATTERN, (string)$entity[1]) !== 1)
			{
				return false;
			}
		}

		return true;
	}

	public static function splitEntities(array $entities): array
	{
		$entities = static::convertEntities($entities);
		$users = [];
		$structureNodes = [];

		foreach ($entities as $entity)
		{
			if (str_starts_with($entity, 'U'))
			{
				$users[] = (int)mb_substr($entity, 1);
			}
			if (static::isStructureNode($entity))
			{
				$structureNodes[] = $entity;
			}
		}

		return [$users, $structureNodes];
	}

	/**
	 * The raw `TYPE` is not enough to tell the supported types apart: a videoconference, an
	 * announcement, a project chat and the general chat all carry the `TYPE` of an ordinary chat and
	 * differ from it by the entity behind them, which is exactly what the extended type reads.
	 */
	public static function isChatTypeSupported(Chat $chat): bool
	{
		return static::isExtendedTypeSupported($chat->getExtendedType(false));
	}

	public static function isExtendedTypeSupported(string $extendedType): bool
	{
		return in_array($extendedType, self::SUPPORTED_CHAT_TYPES, true);
	}

	public static function isSyncAvailable(): bool
	{
		return Loader::includeModule('humanresources')
			&& Storage::instance()->isCompanyStructureConverted()
		;
	}

	/**
	 * Whether the company structure still holds the user in this chat through a linked node.
	 * Returns null when the structure could not be asked at all, so the caller can keep a restriction
	 * instead of widening rights on an unknown answer.
	 */
	public function holdsUser(int $userId): ?bool
	{
		return $this->holdsUsers([$userId])[$userId] ?? null;
	}

	/**
	 * Batch form of holdsUser() for callers that delete members in a loop: without it every member
	 * carrying a structure mark costs its own multi-join query on an interactive request.
	 *
	 * @param array<int|string> $userIds
	 * @return array<int, bool|null> the answer of holdsUser() per user id
	 */
	public function holdsUsers(array $userIds): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));

		if (empty($userIds))
		{
			return [];
		}

		if (!$this->isStructureAvailable())
		{
			return array_fill_keys($userIds, false);
		}

		$answers = [];

		// The chunk size is the contract of the service (TooMuchDataException), not a tuning knob.
		foreach (array_chunk($userIds, self::USERS_PER_STRUCTURE_QUESTION) as $chunk)
		{
			$chunkAnswers = $this->askStructureAboutUsers($chunk);
			$answers += $chunkAnswers;

			if (in_array(null, $chunkAnswers, true))
			{
				// The chunks left are the same failing call repeated - each heavy, each another warning
				// in the log - and unknown is what they would answer anyway. Unknown keeps the refusal.
				return $answers + array_fill_keys($userIds, null);
			}
		}

		return $answers;
	}

	/**
	 * @param list<int> $userIds
	 * @return array<int, bool|null>
	 */
	private function askStructureAboutUsers(array $userIds): array
	{
		try
		{
			$usersNotInNodes = Container::getNodeRelationService()->getUsersNotInRelation(
				RelationEntityType::CHAT,
				(int)$this->chat->getId(),
				$userIds,
			);
		}
		catch (\Throwable $exception)
		{
			$this->reportStructureFailure($userIds, $exception);

			return array_fill_keys($userIds, null);
		}

		// Reverse polarity: the service answers with those who are NOT in the linked nodes.
		$notHeld = array_fill_keys(array_map('intval', $usersNotInNodes), true);
		$answers = [];

		foreach ($userIds as $userId)
		{
			$answers[$userId] = !isset($notHeld[$userId]);
		}

		return $answers;
	}

	/**
	 * Without a trace an operator is left with a refusal and no reason for it - but the trace is
	 * diagnostics, and a broken logger must not turn a fail-closed refusal into an unhandled exception
	 * on the deletion path.
	 *
	 * @param list<int> $userIds
	 */
	private function reportStructureFailure(array $userIds, \Throwable $exception): void
	{
		try
		{
			$this->writeStructureFailureWarning($userIds, $exception);
		}
		catch (\Throwable)
		{
			// There is nowhere left to report a broken logger to.
		}
	}

	/**
	 * @param list<int> $userIds
	 */
	protected function writeStructureFailureWarning(array $userIds, \Throwable $exception): void
	{
		(new LoggerFactory())->createById('im.HumanResources.HoldsUser')?->warning(
			'Failed to ask the company structure whether it holds the users',
			[
				'chatId' => (int)$this->chat->getId(),
				'userIds' => $userIds,
				'exception' => $exception->getMessage(),
			],
		);
	}

	/**
	 * Seam over the static predicate: a subclass can substitute it, the static call cannot be stubbed.
	 */
	protected function isStructureAvailable(): bool
	{
		return static::isSyncAvailable();
	}

	/**
	 * A node kind the portal does not have is not just unusable: its access code simply resolves to
	 * nothing, so the link would silently not be created.
	 */
	public static function areNodeKindsAvailable(array $accessCodes): bool
	{
		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		$hasDepartments = false;
		$hasTeams = false;

		foreach ($accessCodes as $accessCode)
		{
			if (static::hasAnyPrefix($accessCode, AccessCodeType::getIntranetDepartmentTypesPrefixes()))
			{
				$hasDepartments = true;
			}
			elseif (static::hasAnyPrefix($accessCode, AccessCodeType::getTeamTypesPrefixes()))
			{
				$hasTeams = true;
			}
			else
			{
				return false;
			}
		}

		return (!$hasDepartments || static::isSyncAvailable())
			&& (!$hasTeams || static::isTeamsAvailable())
		;
	}

	/**
	 * Server-side counterpart of the org structure data provider that fills the selector: a node the
	 * current user does not see may not be linked.
	 */
	public function areNodesVisible(array $accessCodes): bool
	{
		if (empty($accessCodes))
		{
			return true;
		}

		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		$nodeService = \Bitrix\HumanResources\Public\Service\Container::getNodeService();

		try
		{
			$nodeIds = array_unique($nodeService->getNodeIdsByAccessCodes($accessCodes));

			if (empty($nodeIds))
			{
				return false;
			}

			$visibleNodeIds = $nodeService->findAllByIds(
				nodeIds: $nodeIds,
				nodeTypes: [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
				structureAction: StructureAction::ViewAction,
			)->getIds();
		}
		catch (\Exception)
		{
			return false;
		}

		return empty(array_diff($nodeIds, $visibleNodeIds));
	}

	/**
	 * Asked about the candidates and not about the chat: reading the whole set of links of a chat and
	 * hydrating a node per link costs as much as that chat has links, while the answer is needed for
	 * ten codes at most. A point question per candidate keeps the work of the call bounded by the call
	 * itself.
	 *
	 * @return list<string>
	 */
	public function filterAlreadyLinked(array $accessCodes): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return array_values(array_unique($accessCodes));
		}

		$freshAccessCodes = [];

		foreach (array_unique($accessCodes) as $accessCode)
		{
			if (!$this->isLinked($accessCode))
			{
				$freshAccessCodes[] = $accessCode;
			}
		}

		return $freshAccessCodes;
	}

	/**
	 * A node and the same node "with everything under it" are two links and not one, so the recursion
	 * of the code is part of the question. A code that stands for no node is not linked and not
	 * dropped here: the refusal for it belongs to the linking, which is the only place that knows it.
	 */
	private function isLinked(string $accessCode): bool
	{
		$node = Container::getNodeRepository()->getByAccessCode($accessCode);
		if ($node === null)
		{
			return false;
		}

		return Container::getNodeRelationRepository()->getByNodeIdAndEntityTypeAndEntityIdAndWithChildNodes(
			nodeId: $node->id,
			entityType: RelationEntityType::CHAT,
			entityId: (int)$this->chat->getId(),
			withChildNodes: static::isRecursiveAccessCode($accessCode),
		) !== null;
	}

	private static function isRecursiveAccessCode(string $accessCode): bool
	{
		return static::hasAnyPrefix($accessCode, AccessCodeType::getRecursiveTypesPrefixes());
	}

	/**
	 * @return Result<list<string>> the access codes a link was actually created for. The nodes are
	 * linked one by one and a refusal on one of them leaves the earlier ones linked, so what went
	 * through is reported apart from whether the whole call did.
	 */
	public function link(array $structureNodeIds): Result
	{
		$result = new Result();
		$linkedAccessCodes = [];

		if (empty($structureNodeIds))
		{
			return $result->setResult($linkedAccessCodes);
		}

		if (!Loader::includeModule('humanresources'))
		{
			return $result->setResult($linkedAccessCodes)->addError(new Error(Error::LINK_ERROR));
		}

		$nodeRelationService = Container::getNodeRelationService();

		foreach ($structureNodeIds as $structureNodeId)
		{
			try {
				$relation = $nodeRelationService->linkEntityToNodeByAccessCode(
					$structureNodeId,
					RelationEntityType::CHAT,
					$this->chat->getId(),
					RelationEntitySubtype::fromChatType($this->chat->getType()),
				);

				// An access code standing for no node links nothing and raises nothing. The list is
				// checked as a whole before the call, so a code left over from a deleted node passes
				// that check next to a living one: unanswered here it would be reported as linked.
				if ($relation === null)
				{
					$result->addError(new Error(Error::NODE_NOT_AVAILABLE));

					continue;
				}

				$linkedAccessCodes[] = $structureNodeId;
			}
			catch (\Exception $exception)
			{
				$result->addError(new Error(Error::LINK_ERROR));
			}
		}

		return $result->setResult($linkedAccessCodes);
	}

	public function unlink(array $structureNodeIds): Result
	{
		$result = new Result();

		if (empty($structureNodeIds))
		{
			return $result;
		}

		if (!Loader::includeModule('humanresources'))
		{
			return $result->addError(new Error(Error::UNLINK_ERROR));
		}

		$nodeRelationService = Container::getNodeRelationService();

		foreach ($structureNodeIds as $structureNodeId)
		{
			try {
				$nodeRelationService->unlinkEntityFromNodeByAccessCode(
					$structureNodeId,
					RelationEntityType::CHAT,
					$this->chat->getId(),
				);
			}
			catch (\Exception $exception)
			{
				$result->addError(new Error(Error::UNLINK_ERROR));
			}
		}

		return $result;
	}

	public function unlinkAll(): Result
	{
		$result = new Result();

		if (!Loader::includeModule('humanresources'))
		{
			return $result->addError(new Error(Error::UNLINK_ERROR));
		}

		$nodeRelationRepository = Container::getNodeRelationRepository();

		try
		{
			$nodeRelationRepository->deleteRelationByEntityTypeAndEntityIds(
				RelationEntityType::CHAT,
				[(int)$this->chat->getId()],
			);
		}
		catch (\Exception)
		{
			return $result->addError(new Error(Error::UNLINK_ERROR));
		}

		return $result;
	}

	protected static function convertEntities(array $entities): array
	{
		if (!Loader::includeModule('ui'))
		{
			return [];
		}

		return Converter::convertToFinderCodes($entities);
	}

	/**
	 * @return list<string>
	 */
	public function getNodesAccessCodes(): array
	{
		$accessCodes = [];

		if (!Loader::includeModule('humanresources'))
		{
			return $accessCodes;
		}

		$nodeRelationService = Container::getNodeRelationService();

		$links = $nodeRelationService->findAllRelationsByEntityTypeAndEntityId(
			RelationEntityType::CHAT,
			$this->chat->getId(),
		);

		foreach ($links as $link)
		{
			if ($link->node === null)
			{
				continue;
			}

			if ($link->node->isDepartment())
			{
				$accessCodes[] = $link->withChildNodes
					? str_replace('D', 'DR', $link->node->accessCode)
					: $link->node->accessCode;
			}
			if ($link->node->isTeam())
			{
				$accessCodes[] = $link->withChildNodes
					? AccessCodeType::HrTeamRecursiveType->buildAccessCode($link->nodeId)
					: AccessCodeType::HrTeamType->buildAccessCode($link->nodeId);
			}
		}

		return $accessCodes;
	}

	private static function isStructureNode(string $entity): bool
	{
		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		return static::hasAnyPrefix($entity, [
			...AccessCodeType::getIntranetDepartmentTypesPrefixes(),
			...AccessCodeType::getTeamTypesPrefixes(),
		]);
	}

	private static function hasAnyPrefix(string $accessCode, array $prefixes): bool
	{
		foreach ($prefixes as $prefix)
		{
			if (str_starts_with($accessCode, $prefix))
			{
				return true;
			}
		}

		return false;
	}
}
