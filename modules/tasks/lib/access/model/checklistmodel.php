<?php


namespace Bitrix\Tasks\Access\Model;


use Bitrix\Tasks\CheckList\Internals\CheckList;
use Bitrix\Tasks\Internals\Task\CheckListTable;
use Bitrix\Tasks\Internals\Task\Template;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;

class ChecklistModel
{
	private
		$id = 0,
		$ownerId = 0,
		$entityId = 0;

	private $model;
	private bool $isLoaded = false;

	private ?int $loadedOwnerId = null;
	private ?int $loadedEntityId = null;

	private Type $context = Type::Task;

	/**
	 * Builds the model of a row read from storage: its owner and binding are trusted as they are.
	 *
	 * @param array $data row of the checklist table, not request fields
	 */
	public static function createFromArray(array $data): self
	{
		$model = new self();

		$id = array_key_exists('ID', $data) ? (int)$data['ID'] : 0;
		$model->setId($id);

		$ownerId = array_key_exists('CREATED_BY', $data) ? (int)$data['CREATED_BY'] : 0;
		$model->setOwnerId($ownerId);

		$entityId = array_key_exists('ENTITY_ID', $data) ? (int)$data['ENTITY_ID'] : 0;
		$model->setEntityId($entityId);

		return $model;
	}

	/**
	 * Builds the model of an item addressed by a request. Only the identifier is taken: the rules
	 * decide by owner and binding, so those must come from the stored row and never from the caller.
	 */
	public static function createFromRequest(array $data): self
	{
		$model = new self();

		$id = array_key_exists('ID', $data) ? (int)$data['ID'] : 0;

		return $model->setId($id);
	}

	public static function createFromChecklist(CheckList $checklist)
	{
		return self::createFromArray($checklist->getFields());
	}

	public static function createFromId(int $id): self
	{
		$model = new self();
		return $model->setId($id);
	}

	public function __construct()
	{

	}

	public function setContext(Type $context): self
	{
		if ($context !== $this->context)
		{
			$this->context = $context;
			$this->model = null;
			$this->isLoaded = false;
			$this->loadedOwnerId = null;
			$this->loadedEntityId = null;
		}

		return $this;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getOwnerId(): int
	{
		// template checklist items have no owner column
		if ($this->context === Type::Template)
		{
			return 0;
		}

		if ($this->ownerId)
		{
			return $this->ownerId;
		}

		// the value read from the row belongs to the context: setContext() drops it with the row itself
		return $this->loadedOwnerId ??= (int)$this->load()?->getCreatedBy();
	}

	public function getEntityId(): int
	{
		if ($this->entityId)
		{
			return $this->entityId;
		}

		return $this->loadedEntityId ??= ($this->context === Type::Template)
			? (int)$this->load()?->getTemplateId()
			: (int)$this->load()?->getTaskId()
		;
	}

	private function setId(int $id): self
	{
		$this->id = $id;
		return $this;
	}

	private function setOwnerId(int $id): self
	{
		$this->ownerId = $id;
		return $this;
	}

	private function setEntityId(int $id): self
	{
		$this->entityId = $id;
		return $this;
	}

	private function load()
	{
		// the absence of a row is remembered as well, otherwise every getter pays a query for it
		if ($this->isLoaded)
		{
			return $this->model;
		}

		$this->isLoaded = true;

		if (!$this->id)
		{
			return null;
		}

		$this->model = ($this->context === Type::Template)
			? Template\CheckListTable::getById($this->id)->fetchObject()
			: CheckListTable::getById($this->id)->fetchObject()
		;

		return $this->model;
	}
}