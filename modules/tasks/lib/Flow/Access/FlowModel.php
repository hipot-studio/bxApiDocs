<?php

namespace Bitrix\Tasks\Flow\Access;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\Flow\FlowRegistry;
use Bitrix\Tasks\Flow\Internal\Entity\FlowEntity;
use Bitrix\Tasks\Flow\Internal\Entity\FlowMemberCollection;
use Bitrix\Tasks\Flow\Internal\FlowMemberTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\FlowModel\EntityType;

final class FlowModel implements AccessibleItem
{
	protected int $id = 0;
	protected ?int $ownerId = null;
	protected ?int $creatorId = null;
	protected ?int $projectId = null;
	protected ?int $templateId = null;
	protected static array $userMembers = [];
	protected static array $departments = [];
	protected static array $forAll = [];

	public static function createFromArray(array|Arrayable $data): self
	{
		if ($data instanceof Arrayable)
		{
			$data = $data->toArray();
		}

		$model = new self();

		if (isset($data['id']))
		{
			$model->id = $data['id'];
		}

		if (isset($data['ID']))
		{
			$model->id = $data['ID'];
		}

		$model->ownerId = $data['ownerId'] ?? $data['OWNER_ID'] ?? null;
		$model->projectId = $data['groupId'] ?? $data['GROUP_ID'] ?? null;
		$model->templateId = $data['templateId'] ?? $data['TEMPLATE_ID'] ?? null;

		return $model;
	}

	public static function createFromId(int $itemId): self
	{
		$model = new self();
		$model->id = $itemId;

		return $model;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getOwnerId(): int
	{
		$this->ownerId ??= $this->ownerId = (int)$this->getEntity()?->getOwnerId();

		return $this->ownerId;
	}

	public function getCreatorId(): int
	{
		$this->creatorId ??= (int)$this->getEntity()?->getCreatorId();

		return $this->creatorId;
	}

	public function getMembers(): FlowMemberCollection
	{
		$creators = $this->getEntity(['MEMBERS'])?->getMembers();

		return $creators ?? new FlowMemberCollection();
	}

	public function getProjectId(): int
	{
		$this->projectId ??= (int)$this->getEntity()?->getGroupId();

		return $this->projectId;
	}

	public function isNew(): bool
	{
		return $this->id === 0;
	}

	public function getTemplateId(): int
	{
		$this->templateId ??= (int)$this->getEntity()?->getTemplateId();

		return $this->templateId;
	}

	public function isUserMember(int $userId): bool
	{
		if ($this->id <= 0 || $userId <= 0)
		{
			return false;
		}

		if (isset(self::$userMembers[$this->id][$userId]))
		{
			return self::$userMembers[$this->id][$userId];
		}

		$row = FlowMemberTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE', 'U')
			->where('ENTITY_ID', $userId)
			->where('FLOW_ID', $this->id)
			->exec()
			->fetchObject();

		self::$userMembers[$this->id][$userId] = (null !== $row);

		return self::$userMembers[$this->id][$userId];
	}

	public function isForAll(): bool
	{
		if ($this->id <= 0)
		{
			return false;
		}

		if (isset(self::$forAll[$this->id]))
		{
			return self::$forAll[$this->id];
		}

		$row = FlowMemberTable::query()
			->setSelect(['ID'])
			->where('ACCESS_CODE', 'UA')
			->where('FLOW_ID', $this->id)
			->exec()
			->fetchObject();

		self::$forAll[$this->id] = (null !== $row);

		return self::$forAll[$this->id];
	}

	public function isInFlowDepartments(int $userId): bool
	{
		$userDepartments = Container::getInstance()->getUserDepartmentsInMemoryFacade()->getByUserId($userId);
		if (empty($userDepartments))
		{
			return false;
		}

		$flowDepartments = $this->getFlowDepartments();

		$matchedDepartments = array_intersect($userDepartments, $flowDepartments);

		return !empty($matchedDepartments);
	}

	/**
	 * @return int[]
	 */
	private function getFlowDepartments(): array
	{
		if ($this->id <= 0)
		{
			return [];
		}

		if (isset(self::$departments[$this->id]))
		{
			return self::$departments[$this->id];
		}

		self::$departments[$this->id] = [];

		$oldIdsByType = Container::getInstance()->getFlowMemberRepository()->getDepartmentsOldIdsByType($this->id);
		if (!empty($oldIdsByType))
		{
			$this->loadDepartments($oldIdsByType);
		}

		return self::$departments[$this->id];
	}

	private function loadDepartments(array $oldIdsByType): void
	{
		$departmentsIds = [];
		$subdepartmentsIds = [];

		$oldDepartmentsIds = $oldIdsByType[EntityType::Department->value] ?? [];
		if (!empty($oldDepartmentsIds))
		{
			$departmentsIds = Container::getInstance()->getDepartmentsFacade()->getByOldIds(
				$oldDepartmentsIds,
			);
		}

		$oldDepartmentsWithSubsIds = $oldIdsByType[EntityType::DepartmentRecursive->value] ?? [];
		if (!empty($oldDepartmentsWithSubsIds))
		{
			$subdepartmentsIds = Container::getInstance()->getSubdepartmentsFacade()->getByOldDepartmentsIds(
				$oldDepartmentsWithSubsIds,
			);
		}

		self::$departments[$this->id] = array_values(
			array_unique(
				array_merge($departmentsIds, $subdepartmentsIds),
			),
		);
	}

	protected function getEntity(array $additionalSelect = []): ?FlowEntity
	{
		return FlowRegistry::getInstance()->get($this->id, array_merge(['*'], $additionalSelect));
	}
}
