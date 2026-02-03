<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\CheckList\Node\Nodes;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\FileRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\CheckList\NodeIdGenerator;

class CheckListMapper
{
	public function __construct(
		private readonly NodeIdGenerator $nodeIdGenerator,
		private readonly UserMapper $userMapper,
		private readonly FileRepositoryInterface $fileRepository,
		private readonly Logger $logger,
	)
	{
	}

	public function mapToEntity(array $checkList): Entity\CheckList
	{
		$items = [];
		foreach ($checkList as $id => $item)
		{
			$nodeId = $item['NODE_ID'] ?? $this->nodeIdGenerator->generate($item['TITLE'] . $id);

			$accomplices = array_filter(
				$item['MEMBERS'],
				static fn (array $member): bool => $member['TYPE'] === RoleDictionary::ROLE_ACCOMPLICE,
			);

			$auditors = array_filter(
				$item['MEMBERS'],
				static fn (array $member): bool => $member['TYPE'] === RoleDictionary::ROLE_AUDITOR,
			);

			$allMembers = array_merge($accomplices, $auditors);
			$fileIds = array_unique(array_column($allMembers, 'PERSONAL_PHOTO'));
			Collection::normalizeArrayValuesByInt($fileIds, false);
			$files = $fileIds ? $this->fileRepository->getByIds($fileIds) : null;

			[$entityId, $entityType] = match (true)
			{
				isset($item['TASK_ID']) => [(int)$item['TASK_ID'], Entity\CheckList\Type::Task],
				isset($item['TEMPLATE_ID']) => [(int)$item['TEMPLATE_ID'], Entity\CheckList\Type::Template],
				default => [null, null],
			};

			$items[] = new Entity\CheckList\CheckListItem(
				id: (int)($item['ID'] ?? 0),
				entityId: $entityId,
				entityType: $entityType,
				nodeId: $nodeId,
				title: $item['TITLE'] ?? null,
				creator: $this->mapUser((int)($item['CREATED_BY'] ?? 0)),
				toggledBy: $this->mapUser((int)($item['TOGGLED_BY'] ?? 0)),
				toggledDate: $item['TOGGLED_DATE'] ?? null,
				accomplices: $this->userMapper->mapToCollection($accomplices, $files),
				auditors: $this->userMapper->mapToCollection($auditors, $files),
				attachments: $this->mapAttachmentCollection($item['ATTACHMENTS'] ?? null),
				isComplete: in_array(($item['IS_COMPLETE'] ?? null), ['Y', true], true),
				isImportant: ($item['IS_IMPORTANT'] ?? null) === 'Y',
				parentId: (int)($item['PARENT_ID'] ?? 0),
				parentNodeId: $item['PARENT_NODE_ID'] ?? null,
				sortIndex: (int)($item['SORT_INDEX'] ?? 0),
				actions: $this->mapActions($item['ACTION'] ?? null),
				collapsed: (bool)($item['COLLAPSED'] ?? null) === true,
				expanded: (bool)($item['EXPANDED'] ?? null) === true,
			);
		}

		return new Entity\CheckList(...$items);
	}

	public function mapToNodes(array $checkList): Nodes
	{
		$items = [];
		$nodeIdMapping = [];

		foreach ($checkList as $id => $item)
		{
			if (!is_array($item))
			{
				$this->logger->logWarning($item, 'CheckListMapper.mapToNodes: item is not an array');

				continue;
			}

			$itemId = $item['id'] ?? null;
			$item['id'] = (is_string($itemId) || (int)$itemId === 0) ? null : (int)$itemId;

			$isComplete = $item['isComplete'] ?? null;
			$item['isComplete'] = $isComplete === true || (int)$isComplete > 0;

			$isImportant = $item['isImportant'] ?? null;
			$item['isImportant'] = $isImportant === true || (int)$isImportant > 0;

			$auditors = $this->mapMembers($item['auditors'] ?? [], RoleDictionary::ROLE_AUDITOR);
			$accomplices = $this->mapMembers($item['accomplices'] ?? [], RoleDictionary::ROLE_ACCOMPLICE);

			$item['members'] = array_merge($auditors, $accomplices);

			unset($item['auditors'], $item['accomplices']);

			$items[$id] = $item;
			$nodeIdMapping[$id] = $item['nodeId'] ?? null;
		}

		$converter = new Converter(
			Converter::KEYS
			| Converter::RECURSIVE
			| Converter::TO_SNAKE
			| Converter::TO_UPPER
		);

		$convertedList = $converter->process($items);

		$data = [];
		foreach ($convertedList as $id => $item)
		{
			if (isset($nodeIdMapping[$id]))
			{
				$data[$nodeIdMapping[$id]] = $item;
			}
		}

		$nodes = Nodes::createFromArray(data: $data);
		$nodes->validate();

		return $nodes;
	}

	public function mapToArray(Nodes $nodes): array
	{
		return Converter::toJson()->process($nodes->toArray());
	}

	private function mapUser(int $userId): ?Entity\User
	{
		if ($userId <= 0)
		{
			return null;
		}

		return new Entity\User(
			id: $userId,
		);
	}

	private function mapAttachmentCollection(?array $attachments): Entity\AttachmentCollection
	{
		if (empty($attachments))
		{
			return new Entity\AttachmentCollection();
		}

		$attachments = array_map(
			static fn (array $attachment): Entity\Attachment => new Entity\Attachment(
				id: (int)$attachment['ATTACHMENT_ID'],
				fileId: (string)$attachment['FILE_ID'],
			),
			$attachments,
		);

		return new Entity\AttachmentCollection(...$attachments);
	}

	private function mapActions(?array $actions): ?array
	{
		return Converter::toJson()->process($actions);
	}

	private function mapMembers(array $members, string $type): array
	{
		$mapped = [];
		foreach ($members as $member)
		{
			if (!is_array($member))
			{
				continue;
			}

			$mapped[(int)$member['id']] = [...$member, 'type' => $type];
		}

		return $mapped;
	}
}
