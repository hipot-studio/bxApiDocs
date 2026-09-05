<?php

declare(strict_types=1);

namespace Bitrix\Mail\Dto;

use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Service\SharedSignature\AssignmentResolver;
use Bitrix\Mail\Service\SharedSignature\AssignmentTargetDirectory;
use Bitrix\Main\Type\DateTime;

/**
 * DTO-01: the signature as the REST layer hands it to the client.
 *
 * Fields:
 *   id:int, signature:string, scope:string, ownerId:int, createdBy:int,
 *   assignments:array<{targetType,targetId,targetValue,isFlat,title}>,
 *   assignedMailboxCount:int, isShared:bool, dateModify:string
 */
class SharedSignatureDto
{
	/**
	 * Builds the camelCase DTO array for a single signature entry.
	 *
	 * @param array{
	 *     ID: int,
	 *     CREATED_BY: int,
	 *     OWNER_ID?: int,
	 *     SCOPE?: string,
	 *     SIGNATURE: string,
	 *     DATE_MODIFY?: DateTime|string|null,
	 * } $signatureRow  Raw ORM row or collectValues() result.
	 * @param array<array{
	 *     TARGET_TYPE: string,
	 *     TARGET_ID: int,
	 *     TARGET_VALUE?: string|null,
	 *     IS_FLAT: string,
	 * }> $assignmentRows
	 * @param AssignmentResolver|null $resolver Shared resolver — pass the same instance when building
	 *                                          several DTOs so its caches are reused across rows.
	 * @param AssignmentTargetDirectory|null $directory Names of the targets. Given one, every title
	 *        is the name of the target itself — which is what a screen showing the targets needs.
	 *        Reading a name costs a query per target type, so a caller that does not show them
	 *        leaves it out and gets the basic labels below.
	 * @return array{
	 *     id: int,
	 *     signature: string,
	 *     scope: string,
	 *     ownerId: int,
	 *     createdBy: int,
	 *     assignments: array,
	 *     assignedMailboxCount: int,
	 *     isShared: bool,
	 *     dateModify: string,
	 * }
	 */
	public static function fromRows(
		array $signatureRow,
		array $assignmentRows,
		?AssignmentResolver $resolver = null,
		?AssignmentTargetDirectory $directory = null,
	): array
	{
		$titles = $directory?->getTitles($assignmentRows);

		$assignments = [];
		foreach ($assignmentRows as $key => $a)
		{
			$assignments[] = [
				'targetType' => $a['TARGET_TYPE'],
				'targetId' => (int)$a['TARGET_ID'],
				'targetValue' => (string)($a['TARGET_VALUE'] ?? ''),
				'isFlat' => ($a['IS_FLAT'] === 'Y' || $a['IS_FLAT'] === true),
				'title' => $titles[$key] ?? self::buildAssignmentTitle($a),
			];
		}

		$resolver ??= new AssignmentResolver();
		$resolvedMailboxIds = $resolver->resolveToMailboxIds($assignmentRows);

		$scope = (string)($signatureRow['SCOPE'] ?? SharedSignatureTable::SCOPE_SHARED);

		return [
			'id' => (int)$signatureRow['ID'],
			'signature' => (string)($signatureRow['SIGNATURE'] ?? ''),
			'scope' => $scope,
			'ownerId' => (int)($signatureRow['OWNER_ID'] ?? 0),
			'createdBy' => (int)($signatureRow['CREATED_BY'] ?? 0),
			'assignments' => $assignments,
			'assignedMailboxCount' => count($resolvedMailboxIds),
			// Kept as a derived flag: the mail form still branches on it
			'isShared' => $scope === SharedSignatureTable::SCOPE_SHARED,
			'dateModify' => self::formatDate($signatureRow['DATE_MODIFY'] ?? null),
		];
	}

	/**
	 * Basic label of a single assignment row, used when the caller asks for no names of the targets.
	 * It names the target by its identifier — enough to tell two assignments apart, and not a text
	 * to put in front of a user.
	 */
	private static function buildAssignmentTitle(array $assignment): string
	{
		return match ($assignment['TARGET_TYPE'])
		{
			SharedSignatureAssignmentTable::TARGET_ALL => 'All mailboxes',
			SharedSignatureAssignmentTable::TARGET_MAILBOX => 'Mailbox #' . (int)$assignment['TARGET_ID'],
			SharedSignatureAssignmentTable::TARGET_DEPARTMENT => 'Department #' . (int)$assignment['TARGET_ID'],
			SharedSignatureAssignmentTable::TARGET_USER => 'User #' . (int)$assignment['TARGET_ID'],
			SharedSignatureAssignmentTable::TARGET_SENDER => (string)($assignment['TARGET_VALUE'] ?? ''),
			default => '',
		};
	}

	private static function formatDate($value): string
	{
		if ($value instanceof DateTime)
		{
			return $value->toString();
		}

		return $value === null ? '' : (string)$value;
	}
}
