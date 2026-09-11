<?php

namespace Bitrix\Sign\Agent;

use Bitrix\Main\Application;
use Bitrix\Sign\Type\Document\EntityType as DocumentEntityType;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\Document\Folder\EntityType as FolderEntityType;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\EntityFileCode;
use Bitrix\Sign\Type\EntityType as FileEntityType;
use Bitrix\Sign\Type\Member\Role;

/**
 * One-shot backfill: puts every company safe grid record — a member with a signed result file on a
 * SMART_B2E document (the same population listB2eMembersWithResultFilesForMySafe shows) — into the
 * "No folder" root by creating a root member relation row for it.
 *
 * The class name is kept for a stable agent registration; the payload now targets members instead
 * of documents (folder membership moved from the document to the member).
 *
 * Batched to avoid long table locks on large data sets. Idempotent via NOT EXISTS plus the unique
 * (ENTITY_ID, ENTITY_TYPE) index.
 */
final class UpdateDocumentSafeFolderRelationAgent
{
	private const BATCH_SIZE = 1000;

	public static function run(int $lastId = 0): string
	{
		$connection = Application::getConnection();

		if (
			!$connection->isTableExists('b_sign_member')
			|| !$connection->isTableExists('b_sign_document')
			|| !$connection->isTableExists('b_sign_file')
			|| !$connection->isTableExists('b_sign_document_folder_relation')
		)
		{
			return '';
		}

		$sqlHelper = $connection->getSqlHelper();
		$memberType = $sqlHelper->forSql(FolderEntityType::MEMBER->value);
		$smartB2e = $sqlHelper->forSql(DocumentEntityType::SMART_B2E);
		$fileMemberType = FileEntityType::MEMBER;
		$signedFileCode = EntityFileCode::SIGNED;
		$employeeType = InitiatedByType::EMPLOYEE->toInt();
		$signerRole = Role::convertRoleToInt(Role::SIGNER);
		$endingStatuses = implode(', ', array_map(
			static fn(string $status): string => "'{$sqlHelper->forSql($status)}'",
			DocumentStatus::getEnding(),
		));
		$batchSize = self::BATCH_SIZE;
		$lastId = max(0, $lastId);

		$selectSql = "
				SELECT DISTINCT m.ID
				FROM b_sign_member m
				INNER JOIN b_sign_document d ON d.ID = m.DOCUMENT_ID
				INNER JOIN b_sign_file f
					ON f.ENTITY_ID = m.ID AND f.ENTITY_TYPE_ID = {$fileMemberType} AND f.CODE = {$signedFileCode}
				WHERE m.ID > {$lastId}
					AND d.ENTITY_TYPE = '{$smartB2e}'
					AND d.STATUS IN ({$endingStatuses})
					AND NOT (d.INITIATED_BY_TYPE = {$employeeType} AND m.ROLE = {$signerRole})
					AND NOT EXISTS (
						SELECT 1 FROM b_sign_document_folder_relation r
						WHERE r.ENTITY_ID = m.ID AND r.ENTITY_TYPE = '{$memberType}'
					)
				ORDER BY m.ID
				LIMIT {$batchSize}
			";
		$memberIds = array_map(
			static fn(array $row): int => (int)$row['ID'],
			$connection->query($selectSql)->fetchAll(),
		);
		if ($memberIds === [])
		{
			return '';
		}

		$memberIdsSql = implode(', ', $memberIds);
		$insertSql = "
				INSERT INTO b_sign_document_folder_relation (ENTITY_ID, PARENT_ID, ENTITY_TYPE, DEPTH_LEVEL, CREATED_BY_ID)
				SELECT m.ID, 0, '{$memberType}', 0, m.CREATED_BY_ID
				FROM b_sign_member m
				WHERE m.ID IN ({$memberIdsSql})
					AND NOT EXISTS (
						SELECT 1 FROM b_sign_document_folder_relation r
						WHERE r.ENTITY_ID = m.ID AND r.ENTITY_TYPE = '{$memberType}'
					)
			";

		$connection->queryExecute($insertSql);
		$nextLastId = end($memberIds);

		return count($memberIds) === self::BATCH_SIZE
			? self::class . "::run({$nextLastId});"
			: ''
		;
	}
}
