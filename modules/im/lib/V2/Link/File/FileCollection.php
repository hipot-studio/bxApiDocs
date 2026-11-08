<?php

namespace Bitrix\Im\V2\Link\File;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\FilePopupItem;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Fields;
use Bitrix\Im\Model\LinkFileTable;
use Bitrix\Im\V2\Common\MigrationStatusCheckerTrait;
use Bitrix\Im\V2\Common\SidebarFilterProcessorTrait;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\V2\Link\BaseLinkCollection;

/**
 * @implements \IteratorAggregate<int,FileItem>
 * @method FileItem offsetGet($key)
 */
class FileCollection extends BaseLinkCollection
{
	use SidebarFilterProcessorTrait;
	use MigrationStatusCheckerTrait;

	protected static string $migrationOptionName = 'im_link_file_migration';

	public static function getCollectionElementClass(): string
	{
		return FileItem::class;
	}

	public static function find(
		array $filter = [],
		array $order = ['ID' => 'DESC'],
		?int $limit = null,
		?Context $context = null
	): self
	{
		$context = $context ?? Locator::getContext();

		$fileOrder = static::resolveOrder($order);

		$query = LinkFileTable::query();
		static::processDiskFileFilters($query, $context);

		$query
			->setSelect(['ID', 'DISK_FILE_ID', 'SUBTYPE', 'AUTHOR_ID', 'MESSAGE_ID', 'CHAT_ID', 'DATE_CREATE'])
			->setOrder($fileOrder)
		;
		if (isset($limit))
		{
			$query->setLimit($limit);
		}
		static::processFilters($query, $filter, $fileOrder);
		$collection = new static($query->fetchCollection());
		$collection->fillFiles();

		return $collection;
	}

	public static function getByDiskFileId(int $diskFileId): self
	{
		$entity = LinkFileTable::query()
			->setSelect(['ID', 'MESSAGE_ID', 'CHAT_ID', 'SUBTYPE', 'DISK_FILE_ID', 'DATE_CREATE', 'AUTHOR_ID'])
			->where('DISK_FILE_ID', $diskFileId)
			->fetchCollection()
		;

		return (new static($entity))->fillFiles();
	}

	public static function getByDiskFileIds(array $diskFileIds, Message $message): self
	{
		$entity = LinkFileTable::query()
			->setSelect(['ID', 'MESSAGE_ID', 'CHAT_ID', 'SUBTYPE', 'DISK_FILE_ID', 'DATE_CREATE', 'AUTHOR_ID'])
			->whereIn('DISK_FILE_ID', $diskFileIds)
			->where('MESSAGE_ID', $message->getId())
			->fetchCollection()
		;

		return (new static($entity))->fillFiles();
	}

	public static function getByMessageIds(array $messageIds): self
	{
		$messageIds = array_map('intval', $messageIds);

		if (empty($messageIds))
		{
			return new self();
		}

		$entities = LinkFileTable::query()
			->setSelect(['ID', 'MESSAGE_ID', 'CHAT_ID', 'SUBTYPE', 'DISK_FILE_ID', 'DATE_CREATE', 'AUTHOR_ID'])
			->whereIn('MESSAGE_ID', $messageIds)
			->fetchCollection()
		;

		return new self($entities);
	}

	public function fillFiles(): FileCollection
	{
		$diskFilesIds = $this->getEntityIds();

		$entities = \Bitrix\Im\V2\Entity\File\FileCollection::initByDiskFilesIds($diskFilesIds);

		foreach ($this as $file)
		{
			if ($entities->getById($file->getEntityId()) !== null)
			{
				$file->setEntity($entities->getById($file->getEntityId()));
			}
		}

		return $this;
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		$data = new PopupData([new UserPopupItem(), new FilePopupItem()], $excludedList);

		return parent::getPopupData($excludedList)->merge($data);
	}

	public function save(bool $isGroupSave = false): Result
	{
		if (!static::isMigrationFinished())
		{
			return (new Result())->addError(new FileError(FileError::SAVE_BEFORE_MIGRATION_ERROR));
		}

		return parent::save($isGroupSave);
	}

	public static function linkEntityToMessage(Entity\EntityCollection $entities, Message $message): self
	{
		if ($entities instanceof Entity\File\FileCollection)
		{
			$entities->loadParams();
		}

		$links = parent::linkEntityToMessage($entities, $message);
		$dateCreate = $message->getDateCreate();

		// the tab is ordered by the date of the record, and the column defaults to the moment of the insert:
		// a file attached to an already sent message would take the date of the edit and land out of place
		if ($dateCreate !== null)
		{
			foreach ($links as $link)
			{
				$link->setDateCreate($dateCreate);
			}
		}

		return $links;
	}

	/**
	 * DATE_CREATE wins over ID: a request that asks for the date order carries both keys, because the REST
	 * layer always fills ORDER.ID. The date is not unique, so the id resolves the ties in the same direction.
	 */
	protected static function resolveOrder(array $order): array
	{
		if (isset($order['DATE_CREATE']))
		{
			return ['DATE_CREATE' => $order['DATE_CREATE'], 'ID' => $order['DATE_CREATE']];
		}

		return ['ID' => $order['ID'] ?? 'DESC'];
	}

	protected static function processFilters(Query $query, array $filter, array $order): void
	{
		static::processSidebarFilters($query, $filter, $order);

		if (isset($filter['SEARCH_FILE_NAME']))
		{
			$query->whereLike('FILE.NAME', "{$filter['SEARCH_FILE_NAME']}%");
		}

		$subtypes = static::resolveSubtypes($filter);

		// null means the request asks for no subtype and no group, so every subtype is read
		if ($subtypes !== null)
		{
			if ($subtypes === [])
			{
				// none of the requested values is a known subtype, so the tab stays empty
				$query->where('ID', 0);
			}
			elseif (count($subtypes) > 1)
			{
				$query->whereIn('SUBTYPE', $subtypes);
			}
			else
			{
				$query->where('SUBTYPE', array_values($subtypes)[0]);
			}
		}
	}

	/**
	 * @return string[]|null Null when the request asks for no subtype and no group.
	 */
	protected static function resolveSubtypes(array $filter): ?array
	{
		if (isset($filter['SUBTYPE']))
		{
			return Subtype::getSubtypeFilter($filter['SUBTYPE']);
		}

		if (isset($filter['GROUP']))
		{
			return SubtypeGroup::getSubtypeFilter($filter['GROUP']);
		}

		return null;
	}

	protected static function processDiskFileFilters(Query $query, Context $context): void
	{
		$securityContext = new \Bitrix\Disk\Security\DiskSecurityContext($context->getUserId());
		$parameters = [];
		$parameters = \Bitrix\Disk\Driver::getInstance()
			->getRightsManager()
			->addRightsCheck($securityContext, $parameters, ['FILE.ID'])
		;

		/** @var Fields\ExpressionField $field */
		$field = $parameters['runtime'][0];
		$field->configureValueType(Fields\IntegerField::class);
		$query->registerRuntimeField($field);
	}
}
