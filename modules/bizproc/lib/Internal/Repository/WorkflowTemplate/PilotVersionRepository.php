<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate;

use Bitrix\Bizproc\Internal\Entity\Document\DocumentComplexType;
use Bitrix\Bizproc\Internal\Entity\WorkflowTemplate\PilotVersion;
use Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotAccessTable;
use Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Application;
use Bitrix\Main\Repository\Exception\PersistenceException;

/**
 * The only entry to the pilot snapshot and its audience. They live in two tables but are born and
 * die together, and that invariant holds only while nothing writes to those tables directly.
 *
 * The writing methods run inside a transaction opened by the caller and never open one themselves:
 * the publication command puts the snapshot, the audience and the pilot mark of the template into
 * the same transaction.
 */
class PilotVersionRepository
{
	/**
	 * Serializes pilot writes with every write of the common template row.
	 *
	 * The caller owns the transaction. The lock is therefore held until that caller commits or rolls back.
	 */
	public function lockTemplateForUpdate(int $templateId): bool
	{
		return WorkflowTemplateTable::lockRow($templateId);
	}

	public function getByTemplateId(int $templateId): ?PilotVersion
	{
		$row = WorkflowTemplatePilotTable::query()
			->setSelect([
				'ID',
				'MODULE_ID',
				'ENTITY',
				'DOCUMENT_TYPE',
				'TEMPLATE_DATA',
				'REVISION',
				'CREATED_BY',
				'CREATED',
			])
			->where('TEMPLATE_ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		$pilotId = (int)$row['ID'];

		return new PilotVersion(
			templateId: $templateId,
			documentType: new DocumentComplexType(
				(string)$row['MODULE_ID'],
				(string)$row['ENTITY'],
				(string)$row['DOCUMENT_TYPE'],
			),
			executableFields: is_array($row['TEMPLATE_DATA']) ? $row['TEMPLATE_DATA'] : [],
			revision: (string)$row['REVISION'],
			createdBy: (int)$row['CREATED_BY'],
			created: $row['CREATED'],
			accessCodes: $this->getAudienceCodesByPilotId($pilotId),
			id: $pilotId,
		);
	}

	/**
	 * Whether the template has a pilot version at all - the question asked before the audience is, on the
	 * start path of every process. The snapshot itself carries the executable scheme, and reading it to
	 * find out that the employee is not a participant is the heaviest way to learn nothing.
	 */
	public function hasPilot(int $templateId): bool
	{
		return $templateId > 0 && $this->findPilotId($templateId) !== null;
	}

	/**
	 * The shape {@see \CBPWorkflowTemplateLoader::loadWorkflowFromArray()} reads, so the runtime can
	 * start the pilot schema the same way it starts the common one.
	 */
	public function getExecutableFields(int $templateId): ?array
	{
		$row = WorkflowTemplatePilotTable::query()
			->setSelect(['TEMPLATE_DATA'])
			->where('TEMPLATE_ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		if (!$row || !is_array($row['TEMPLATE_DATA']))
		{
			return null;
		}

		$executableFields = $row['TEMPLATE_DATA'];

		return [
			'ID' => $templateId,
			'TEMPLATE' => $executableFields['TEMPLATE'] ?? [],
			'PARAMETERS' => $executableFields['PARAMETERS'] ?? [],
			'VARIABLES' => $executableFields['VARIABLES'] ?? [],
			'CONSTANTS' => $executableFields['CONSTANTS'] ?? [],
		];
	}

	/**
	 * Runtime-only snapshot without the audience and editor metadata.
	 *
	 * @return array{revision: string, executableFields: array}|null
	 */
	public function getRuntimeVersion(int $templateId, ?int $expectedPilotId = null): ?array
	{
		$query = WorkflowTemplatePilotTable::query()
			->setSelect(['TEMPLATE_DATA', 'REVISION'])
			->where('TEMPLATE_ID', $templateId)
			->setLimit(1)
		;
		if ($expectedPilotId !== null)
		{
			$query->where('ID', $expectedPilotId);
		}

		$row = $query->fetch();

		if (!$row || !is_array($row['TEMPLATE_DATA']))
		{
			return null;
		}

		return [
			'revision' => (string)$row['REVISION'],
			'executableFields' => $row['TEMPLATE_DATA'],
		];
	}

	/**
	 * Which of the given templates a pilot acts on - the question a list asks about a whole page at once,
	 * because asking it row by row would cost a query per row.
	 *
	 * A snapshot is what is looked for, so a template whose pilot has been stopped is not in the answer.
	 *
	 * @param int[] $templateIds
	 * @return int[]
	 */
	public function findTemplateIdsWithPilot(array $templateIds): array
	{
		$templateIds = array_values(array_filter(array_unique(array_map('intval', $templateIds))));
		if (!$templateIds)
		{
			return [];
		}

		$rows = WorkflowTemplatePilotTable::query()
			->setSelect(['TEMPLATE_ID'])
			->whereIn('TEMPLATE_ID', $templateIds)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'TEMPLATE_ID'));
	}

	/**
	 * @throws PersistenceException
	 */
	public function replace(PilotVersion $version): void
	{
		$this->deleteByTemplateId($version->templateId);

		$pilotId = $this->addSnapshot($version);
		$this->addAudience($pilotId, $version->accessCodes);
	}

	/**
	 * @param string[] $accessCodes
	 * @throws PersistenceException
	 */
	public function replaceAudience(int $templateId, int $expectedPilotId, array $accessCodes): bool
	{
		if (!$this->lockExpectedPilot($templateId, $expectedPilotId))
		{
			return false;
		}

		WorkflowTemplatePilotAccessTable::deleteByFilter(['=PILOT_ID' => $expectedPilotId]);
		$this->addAudience($expectedPilotId, $accessCodes);

		return true;
	}

	public function deleteExpected(int $templateId, int $expectedPilotId): bool
	{
		$connection = Application::getConnection();
		$connection->queryExecute(
			'DELETE FROM '. WorkflowTemplatePilotTable::getTableName()
			. ' WHERE TEMPLATE_ID = '. $templateId
			. ' AND ID = '. $expectedPilotId,
		);
		$isOwner = $connection->getAffectedRowsCount() > 0;
		WorkflowTemplatePilotTable::cleanCache();

		if ($isOwner)
		{
			WorkflowTemplatePilotAccessTable::deleteByFilter(['=PILOT_ID' => $expectedPilotId]);
		}

		return $isOwner;
	}

	/**
	 * Deletes the snapshot with its audience and tells whether this very call is the one that deleted it.
	 *
	 * Two concurrent stops of the same pilot both see the snapshot in place - reading it before the delete
	 * decides nothing - and only the database says which of them owned the row. Everything a stop does
	 * besides the delete belongs to that owner alone: keeping the scheme as a draft would otherwise be done
	 * twice, and the portal-wide answer about the pilots would be dropped for a change that never happened.
	 */
	public function deleteByTemplateId(int $templateId): bool
	{
		$pilotId = $this->findPilotId($templateId);
		if ($pilotId === null)
		{
			return false;
		}

		$connection = Application::getConnection();

		// the snapshot goes first and by its own id: the number of the rows this statement deleted is the
		// only answer to "was it me", and an ORM delete gives no access to it
		$connection->queryExecute(
			'DELETE FROM '. WorkflowTemplatePilotTable::getTableName(). ' WHERE ID = '. $pilotId
		);
		$isOwner = $connection->getAffectedRowsCount() > 0;
		WorkflowTemplatePilotTable::cleanCache();

		WorkflowTemplatePilotAccessTable::deleteByFilter(['=PILOT_ID' => $pilotId]);

		return $isOwner;
	}

	/**
	 * @return string[]
	 */
	public function getAudienceCodes(int $templateId): array
	{
		$pilotId = $this->findPilotId($templateId);

		return $pilotId === null ? [] : $this->getAudienceCodesByPilotId($pilotId);
	}

	private function findPilotId(int $templateId): ?int
	{
		$row = WorkflowTemplatePilotTable::query()
			->setSelect(['ID'])
			->where('TEMPLATE_ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['ID'] : null;
	}

	private function lockExpectedPilot(int $templateId, int $expectedPilotId): bool
	{
		$row = Application::getConnection()->query(
			'SELECT ID FROM '. WorkflowTemplatePilotTable::getTableName()
			. ' WHERE TEMPLATE_ID = '. $templateId
			. ' AND ID = '. $expectedPilotId
			. ' FOR UPDATE',
		)->fetch();

		return $row !== false;
	}

	/**
	 * @return string[]
	 */
	private function getAudienceCodesByPilotId(int $pilotId): array
	{
		$rows = WorkflowTemplatePilotAccessTable::query()
			->setSelect(['ACCESS_CODE'])
			->where('PILOT_ID', $pilotId)
			->exec()
			->fetchAll()
		;

		return array_map('strval', array_column($rows, 'ACCESS_CODE'));
	}

	/**
	 * @throws PersistenceException
	 */
	private function addSnapshot(PilotVersion $version): int
	{
		try
		{
			$result = WorkflowTemplatePilotTable::add([
				'TEMPLATE_ID' => $version->templateId,
				'MODULE_ID' => $version->documentType->moduleId,
				'ENTITY' => $version->documentType->entity,
				'DOCUMENT_TYPE' => $version->documentType->type,
				'TEMPLATE_DATA' => $version->executableFields,
				'REVISION' => $version->revision,
				'CREATED_BY' => $version->createdBy,
				'CREATED' => $version->created,
			]);
		}
		catch (\Exception $exception)
		{
			// a concurrent publication loses on the unique index of TEMPLATE_ID and lands here
			throw new PersistenceException($exception->getMessage(), $exception);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to save the pilot version: '. implode('; ', $result->getErrorMessages())
			);
		}

		return (int)$result->getId();
	}

	/**
	 * @param string[] $accessCodes
	 * @throws PersistenceException
	 */
	private function addAudience(int $pilotId, array $accessCodes): void
	{
		$rows = [];
		foreach (array_unique($accessCodes) as $accessCode)
		{
			$rows[] = ['PILOT_ID' => $pilotId, 'ACCESS_CODE' => $accessCode];
		}

		// an emptied audience is a valid state of the storage; whether it is a valid input of the
		// publication is decided above the repository
		if (!$rows)
		{
			return;
		}

		try
		{
			$result = WorkflowTemplatePilotAccessTable::addMulti($rows, true);
		}
		catch (\Exception $exception)
		{
			throw new PersistenceException($exception->getMessage(), $exception);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to save the pilot audience: '. implode('; ', $result->getErrorMessages())
			);
		}
	}
}
