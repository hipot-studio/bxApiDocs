<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Debug;
use Bitrix\Bizproc\Internal\Entity\Debugger\DocumentId;
use Bitrix\Bizproc\Internal\Model\Debugger\DebugTable;
use Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug;
use Bitrix\Bizproc\Internal\Repository\Mapper\DebugOrmMapper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Exception;
use Throwable;

final class DebugRepository implements DebugRepositoryInterface
{
	private DebugOrmMapper $debugOrmMapper;

	public function __construct(?DebugOrmMapper $debugOrmMapper = null)
	{
		$this->debugOrmMapper = $debugOrmMapper ?? new DebugOrmMapper();
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function findByUserId(int $userId): ?Debug
	{
		$row = DebugTable::getRow([
			'filter' => [
				'=USER_ID' => $userId,
				'=ENABLED' => 'Y',
			],
			'order' => ['ID' => 'DESC'],
		]);

		return $row ? $this->debugOrmMapper->convertFromArray($row) : null;
	}

	public function first(int $id): ?Debug
	{
		$row = DebugTable::getById($id)->fetch();

		return $row ? $this->debugOrmMapper->convertFromArray($row) : null;
	}

	public function disable(int $userId, int $templateId, ?DocumentId $documentId = null): void
	{
		$filter = [
			'=USER_ID' => $userId,
			'=TEMPLATE_ID' => $templateId,
		];

		if ($documentId !== null)
		{
			$filter['=MODULE_ID'] = $documentId->moduleId;
			$filter['=ENTITY'] = $documentId->entity;
			$filter['=DOCUMENT_ID'] = $documentId->documentId;
		}
		else
		{
			$filter['=DOCUMENT_ID'] = null;
		}

		$list = DebugTable::getList([
			'filter' => $filter,
		]);

		while ($row = $list->fetch())
		{
			DebugTable::update($row['ID'], ['ENABLED' => 'N']);
		}
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function disableAllForUser(int $userId): void
	{
		DebugTable::query()
			->setSelect(['ID', 'USER_ID', 'ENABLED'])
			->where('USER_ID', $userId)
			->where('ENABLED', 'Y')
			->fetchCollection()
			->walk(fn(EO_Debug $item) => $item->setEnabled('N'))
			->save()
		;
	}

	/**
	 * @throws Exception
	 */
	public function save(Debug $debug): void
	{
		$documentId = $debug->getDocumentId();

		$data = [
			'USER_ID' => $debug->getUserId(),
			'TEMPLATE_ID' => $debug->getTemplateId(),
			'MODULE_ID' => $documentId?->moduleId,
			'ENTITY' => $documentId?->entity,
			'DOCUMENT_ID' => $documentId?->documentId,
			'ENABLED' => $debug->isEnabled() ? 'Y' : 'N',
			'CREATED_AT' => $debug->getCreatedAt(),
			'UPDATED_AT' => $debug->getUpdatedAt(),
		];

		if ($debug->isNew())
		{
			$result = DebugTable::add($data);

			if ($result->isSuccess())
			{
				$debug->setId($result->getId());
				$debug->setCreatedAt($result->getObject()->getCreatedAt());
				$debug->setUpdatedAt($result->getObject()->getUpdatedAt());
			}
		}
		else
		{
			$result = DebugTable::update($debug->getId(), $data);

			if ($result->isSuccess())
			{
				$debug->setUpdatedAt($result->getObject()->getUpdatedAt());
			}
		}
	}

	public function delete(int $id): void
	{
		DebugTable::delete($id);
	}

	public function exists(int $userId, int $templateId, ?DocumentId $documentId = null): bool
	{
		try
		{
			$debug = $this->find($userId, $templateId, $documentId);

			return $debug !== null && $debug->isEnabled();
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function find(int $userId, int $templateId, ?DocumentId $documentId = null, ?bool $isEnabled = null): ?Debug
	{
		$filter = [
			'=USER_ID' => $userId,
			'=TEMPLATE_ID' => $templateId,
			'=MODULE_ID' => $documentId?->moduleId,
			'=ENTITY' => $documentId?->entity,
			'=DOCUMENT_ID' => $documentId?->documentId,
		];

		if ($isEnabled !== null)
		{
			$filter['=ENABLED'] = $isEnabled ? 'Y' : 'N';
		}

		$row = DebugTable::getRow(['filter' => $filter]);

		return !empty($row) ? $this->debugOrmMapper->convertFromArray($row) : null;
	}
}
