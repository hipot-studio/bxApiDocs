<?php

namespace Bitrix\HumanResources\Integration\Socialnetwork;

use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Repository\NodeRelationRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\SocialNetwork\Collab\Access\CollabAccessController;
use Bitrix\SocialNetwork\Collab\Access\CollabDictionary;
use Bitrix\Socialnetwork\Collab\Provider\CollabProvider;
use Bitrix\Socialnetwork\Collab\Provider\CollabQuery;
use Bitrix\Socialnetwork\Integration\Im\Chat\Workgroup;

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

	public function create()
	{
		// ToDo: implement
	}

	public function filterByPermissions(array $ids, int $userId): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		// ToDo: improve performance
		return array_filter($ids, fn($item) => CollabAccessController::can($userId, CollabDictionary::INVITE, $item));
	}

	public function getCollabsByNode(Item\Node $node, ?int $userId = null): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		$collabCollection = $this->nodeRelationRepository->findRelationsByNodeIdAndRelationType(
			nodeId: $node->id,
			relationEntityType: RelationEntityType::COLLAB,
			limit: 0,
		);

		$collabIds = $collabCollection->map(fn($item) => $item->entityId);
		$indirectCollabs = $collabCollection->filter(fn($item) => $item->originalNodeId !== $node->id)
			->map(fn($item) => $item->entityId)
		;

		if (count($collabIds) === 0)
		{
			return [];
		}

		$userId = $userId ?? CurrentUser::get()->getId();

		$collabService = new CollabProvider();
		$collabQuery = (new CollabQuery($userId))
			->addWhere(new Condition('ID', 'in', $collabIds))
			->setSelect(['ID', 'NAME', 'IMAGE_ID'])
			->setAccessCheck()
		;

		$collabs = $collabService->getList($collabQuery);
		$collabsChatData = Workgroup::getChatData([
			'group_id' => $collabs->getIdList(),
		]);
		$items = $collabs->toArray();

		$collabs->map();
		return array_map(
			function ($item) use ($indirectCollabs, $collabsChatData) {
				$avatar = null;
				if ($file = \CFile::GetFileArray($item['IMAGE_ID']))
				{
					$fileInfo = \CFile::ResizeImageGet(
						$file,
						[
							'width' => 100,
							'height' => 100,
						],
						BX_RESIZE_IMAGE_EXACT,
					);

					$avatar = $fileInfo['src'] ?? null;
				}

				return [
					'id' => $item['ID'],
					'name' => $item['NAME'],
					'avatar' => $avatar,
					'isIndirect' => in_array($item['ID'], $indirectCollabs, true),
					'dialogId' => 'chat' . ($collabsChatData[$item['ID']] ?? null),
					'hasAccess' => true,
				];
			},
			array_values($items)
		);
	}
}
