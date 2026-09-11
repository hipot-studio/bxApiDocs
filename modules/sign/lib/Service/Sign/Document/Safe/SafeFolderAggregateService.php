<?php

namespace Bitrix\Sign\Service\Sign\Document\Safe;

use Bitrix\Sign\Item;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\MemberService;

/**
 * Batch aggregation of a safe folder's members into the folder-row data (DTO-01 aggregates):
 * unique participant users, representatives, senders, companies and role codes per folder. Pure
 * in-memory grouping over an already-fetched member set — no query is issued here, so the caller
 * must load members, documents and companies in batch beforehand (no N+1). Consumers resolve the
 * user ids to {id,name,photo} and the role codes to captions with their own presentation layer.
 */
final class SafeFolderAggregateService
{
	private readonly MemberService $memberService;

	public function __construct(?MemberService $memberService = null)
	{
		$this->memberService = $memberService ?? Container::instance()->getMemberService();
	}

	/**
	 * @param array<int, Item\Document> $documentsById documentId => document
	 * @param array<int, array{id:int, title:string}|null> $companyByDocumentId documentId => company
	 * @return array<int, array{
	 *     participantIds: list<int>,
	 *     representativeIds: list<int>,
	 *     senderIds: list<int>,
	 *     companies: list<array{id:int, title:string}>,
	 *     roleCodes: list<string>,
	 * }> keyed by folder id
	 */
	public function aggregateByFolder(
		Item\MemberCollection $members,
		array $documentsById,
		array $companyByDocumentId,
	): array
	{
		$participants = [];
		$representatives = [];
		$senders = [];
		$companies = [];
		$roleCodes = [];

		foreach ($members as $member)
		{
			$folderId = $member->folderId;
			if ($folderId === null || $folderId <= 0)
			{
				continue;
			}

			$document = $documentsById[$member->documentId] ?? null;
			if ($document === null)
			{
				continue;
			}

			$participantId = $this->memberService->getUserIdForMember($member, $document);
			if (is_int($participantId) && $participantId > 0)
			{
				$participants[$folderId][$participantId] = true;
			}

			if (is_int($document->representativeId) && $document->representativeId > 0)
			{
				$representatives[$folderId][$document->representativeId] = true;
			}

			if (is_int($document->createdById) && $document->createdById > 0)
			{
				$senders[$folderId][$document->createdById] = true;
			}

			$company = $companyByDocumentId[$member->documentId] ?? null;
			if (is_array($company) && isset($company['id']))
			{
				$companies[$folderId][(int)$company['id']] = [
					'id' => (int)$company['id'],
					'title' => (string)($company['title'] ?? ''),
				];
			}

			if (is_string($member->role) && $member->role !== '')
			{
				$roleCodes[$folderId][$member->role] = true;
			}
		}

		$result = [];
		$folderIds = array_unique(array_merge(
			array_keys($participants),
			array_keys($representatives),
			array_keys($senders),
			array_keys($companies),
			array_keys($roleCodes),
		));
		foreach ($folderIds as $folderId)
		{
			$result[$folderId] = [
				'participantIds' => array_keys($participants[$folderId] ?? []),
				'representativeIds' => array_keys($representatives[$folderId] ?? []),
				'senderIds' => array_keys($senders[$folderId] ?? []),
				'companies' => array_values($companies[$folderId] ?? []),
				'roleCodes' => array_keys($roleCodes[$folderId] ?? []),
			];
		}

		return $result;
	}
}
