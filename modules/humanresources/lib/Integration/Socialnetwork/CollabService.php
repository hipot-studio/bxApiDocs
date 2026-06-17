<?php

namespace Bitrix\HumanResources\Integration\Socialnetwork;

use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Repository\NodeRelationRepository;
use Bitrix\HumanResources\Result\Integration\Socialnetwork\CreateCollabResult;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\SocialNetwork\Collab\Access\CollabAccessController;
use Bitrix\SocialNetwork\Collab\Access\CollabDictionary;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabAddCommand;
use Bitrix\Socialnetwork\Collab\Control\Decorator\RequirementDecorator;
use Bitrix\Socialnetwork\Collab\Provider\CollabProvider;
use Bitrix\Socialnetwork\Collab\Provider\CollabQuery;
use Bitrix\Socialnetwork\Control\Decorator\AccessDecorator;
use Bitrix\Socialnetwork\Integration\Im\Chat\Workgroup;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Public\Command\Project\AddProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\Project;
use Bitrix\Socialnetwork\V2\Public\Service;

class CollabService
{
	private NodeRelationRepository $nodeRelationRepository;

	public function __construct()
	{
		$this->nodeRelationRepository = Container::getNodeRelationRepository();
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('socialnetwork');
	}

	public function isProjectsAvailable(): bool
	{
		return (
			Loader::includeModule('socialnetwork')
			&& class_exists(Feature::class)
			&& Feature::isNewProjectsOn()
		);
	}

	public function create(Item\Node $node, array $headIds, int $userId): CreateCollabResult
	{
		$result = new CreateCollabResult();

		if (!$this->isAvailable())
		{
			return $result->addError(new Error(Loc::getMessage('HUMANRESOURCES_COLLAB_SERVICE_NOT_AVAILABLE_MSGVER_1')));
		}

		if ($this->isProjectsAvailable())
		{
			return $this->createProject($node, $headIds, $userId);
		}

		return $this->createCollab($node, $headIds, $userId);
	}

	private function createCollab(Item\Node $node, array $headIds, int $userId): CreateCollabResult
	{
		$result = new CreateCollabResult();

		$collabData = [
			'ownerId' => $this->pickOwnerId($headIds),
			'name' => $this->getAvailableName($node, $userId),
			'initiatorId' => $userId,
		];
		$command = CollabAddCommand::createFromArray($collabData);

		$collabService = ServiceLocator::getInstance()->get('socialnetwork.collab.service');
		$addResult = (new RequirementDecorator(new AccessDecorator($collabService)))->add($command);

		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		return $result->setCollabId((int)$addResult->getCollab()?->getId());
	}

	public function filterByPermissions(array $ids, int $userId): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		if ($this->isProjectsAvailable())
		{
			return (new Service\Project\Access())->filterInvitable($userId, $ids);
		}

		// ToDo: improve performance
		return array_filter($ids, fn(int $item): bool => CollabAccessController::can($userId, CollabDictionary::INVITE, $item));
	}

	public function getCollabsByNode(Item\Node $node, int $userId): array
	{
		if (!$this->isAvailable())
		{
			return ['collabs' => [], 'noAccessCollabs' => 0];
		}

		$collabCollection = $this->nodeRelationRepository->findRelationsByNodeIdAndRelationType(
			nodeId: $node->id,
			relationEntityType: RelationEntityType::COLLAB,
			limit: 0,
		);

		$collabIds = $collabCollection->map(fn($item): int => $item->entityId);
		$indirectCollabs = [];
		foreach ($collabCollection as $item)
		{
			if ($item->nodeId !== $node->id)
			{
				$indirectCollabs[$item->entityId] = $item->nodeId;
			}
		}

		if (count($collabIds) === 0)
		{
			return ['collabs' => [], 'noAccessCollabs' => 0];
		}

		$collabProvider = new CollabProvider();

		$allCollabQuery = (new CollabQuery($userId))
			->addWhere(new Condition('ID', 'in', $collabIds))
			->setSelect(['ID'])
		;
		$allCollabsCount = $collabProvider->getCount($allCollabQuery);

		$collabQuery = (new CollabQuery($userId))
			->addWhere(new Condition('ID', 'in', $collabIds))
			->setSelect(['ID', 'NAME', 'IMAGE_ID'])
			->setAccessCheck()
		;

		$collabs = $collabProvider->getList($collabQuery);
		$collabsChatData = Workgroup::getChatData([
			'group_id' => $collabs->getIdList(),
		]);

		$noAccessCollabs = $allCollabsCount - $collabs->count();
		$items = $collabs->toArray();

		$avatarsArray = [];
		if (!empty($items))
		{
			$res = \CFile::getList(arFilter: ['@ID' => array_map(static fn(array $item): int => (int)$item['IMAGE_ID'], $items)]);
			while ($file = $res->fetch()) {
				$fileInfo = \CFile::ResizeImageGet(
					$file,
					['width' => 100, 'height' => 100],
					BX_RESIZE_IMAGE_EXACT,
				);
				$avatarsArray[$file['ID']] = $fileInfo['src'] ?? null;
			}
		}

		$collabsResult = array_map(
			fn(array $item): array => [
				'id' => (int)$item['ID'],
				'title' => $item['NAME'],
				'type' => RelationEntityType::COLLAB,
				'subtitle' => $this->getCollabSubtitle(),
				'avatar' => $avatarsArray[$item['IMAGE_ID']] ?? null,
				'originalNodeId' => $indirectCollabs[$item['ID']] ?? null,
				'dialogId' => 'chat' . ($collabsChatData[$item['ID']] ?? null),
				'hasAccess' => true,
			],
			array_values($items),
		);

		return [
			'collabs' => $collabsResult,
			'collabsNoAccess' => $noAccessCollabs,
		];
	}

	/**
	 * @param int[] $headIds non-empty list of head user IDs
	 */
	private function pickOwnerId(array $headIds): int
	{
		return $headIds[array_rand($headIds)];
	}

	private function createProject(Item\Node $node, array $headIds, int $userId): CreateCollabResult
	{
		$result = new CreateCollabResult();

		if (!(new Service\Project\Access())->canCreate($userId))
		{
			return $result->addError(new Error(
				Loc::getMessage('HUMANRESOURCES_COLLAB_SERVICE_PROJECT_CREATE_ACCESS_DENIED'),
				'ACCESS_DENIED',
			));
		}

		$project = Project::mapFromArray([
			'name' => $this->getAvailableName($node, $userId),
			'ownerId' => $this->pickOwnerId($headIds),
			'privacyType' => 'closed',
		]);

		try
		{
			$addResult = (new AddProjectCommand(
				input: $project,
				userId: $userId,
				enforceInitiatorMembership: false,
			))->run();
		}
		catch (CommandValidationException $e)
		{
			return $result->addErrors($e->getValidationErrors());
		}
		catch (CommandException $e)
		{
			return $result->addError(new Error($e->getMessage()));
		}

		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		$projectId = (int)($addResult->getData()['projectId'] ?? 0);
		if ($projectId === 0)
		{
			return $result->addError(new Error(Loc::getMessage('HUMANRESOURCES_COLLAB_SERVICE_PROJECT_CREATE_FAILED')));
		}

		return $result->setCollabId($projectId);
	}

	/**
	 * Resolves a unique name for a new socialnetwork group created from a node — applies both to
	 * legacy collabs and V2 projects (they share b_sonet_group with TYPE='collab' and uniqueness
	 * is enforced on NAME). Returns the node name as is if free, otherwise "<name> №N" with the
	 * smallest free N.
	 *
	 * @param Item\Node $node
	 * @param int $userId
	 * @return string
	 * @throws \Bitrix\Main\ArgumentException
	 */
	private function getAvailableName(Item\Node $node, int $userId): string
	{
		$duplicateTitleStart = Loc::getMessage('HUMANRESOURCES_COLLAB_SERVICE_NAME_SEPARATOR', [
			'#COLLAB_NAME#' => $node->name,
		]);
		$suffixPosition = (string)(mb_strlen($duplicateTitleStart) + 1);

		$collabProvider = new CollabProvider();
		$allCollabQuery = (new CollabQuery($userId))
			->setSelect([
				'ID',
				new \Bitrix\Main\ORM\Fields\ExpressionField(
					'NUM_SUFFIX',
					"SUBSTRING(%s, $suffixPosition)",
					['NAME'],
				),
			])
			->setWhere(\Bitrix\Main\ORM\Query\Query::filter()
				->logic('or')
				->where('NAME', '=', $node->name)
				->whereLike('NAME', $duplicateTitleStart . '%')
			)
			->setOrder(['ID' => 'DESC'])
			->setLimit(50)
		;

		$collabs = $collabProvider->getList($allCollabQuery)->toArray();

		if (empty($collabs))
		{
			return $node->name;
		}

		$maxNumSuffix = array_reduce($collabs, fn(int $max, array $item) => max($max, (int)$item['NUM_SUFFIX']), 0);

		return $duplicateTitleStart . ($maxNumSuffix + 1);
	}

	private function getCollabSubtitle(): string
	{
		if ($this->isProjectsAvailable())
		{
			return Loc::getMessage('HUMANRESOURCES_COLLAB_SERVICE_PROJECT_SUBTITLE');
		}

		return Loc::getMessage('HUMANRESOURCES_COLLAB_SERVICE_COLLAB_SUBTITLE');
	}
}
