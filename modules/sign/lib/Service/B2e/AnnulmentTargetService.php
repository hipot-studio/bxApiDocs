<?php

namespace Bitrix\Sign\Service\B2e;

use Bitrix\Sign\Item\B2e\AnnulmentTarget;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\DocumentCollection;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;

/**
 * Tells every row of a grid page which signing it speaks about (ALG-01).
 *
 * A row of the company safe showing the representative of an employee-initiated document
 * speaks about the employee's signing: the safe excludes that signer row from its result
 * set by construction, so nothing else on the page can carry it. Everywhere else - and in
 * the process grid, where the signer row is present and answers for itself - the target
 * repeats the displayed member.
 *
 * The service reads neither the feature flag nor the permissions: whether the feature is
 * released and whether the substitution applies to this grid mode arrive as arguments, and
 * the owner scope is applied by the caller on top of the eligibility predicate.
 *
 * The second carrier of the same substitution class is the annulment status filter of the
 * safe: MemberRepository::buildSafeAnnulmentCondition().
 */
final class AnnulmentTargetService
{
	private MemberRepository $memberRepository;

	public function __construct(?MemberRepository $memberRepository = null)
	{
		$this->memberRepository = $memberRepository ?? Container::instance()->getMemberRepository();
	}

	/**
	 * @param bool $substitutionAllowed true for the company safe only; the process grid
	 *        lists the signer rows themselves, so substituting there would hand one
	 *        signer row the address and the mark of another.
	 *
	 * @return array<int, AnnulmentTarget> member id => target
	 */
	public function resolveForPage(
		MemberCollection $members,
		DocumentCollection $documents,
		bool $enabled,
		bool $substitutionAllowed,
	): array
	{
		if (!$enabled || $members->isEmpty())
		{
			return [];
		}

		$documentsById = $documents->getArrayByIds();
		$signerByDocumentId = $substitutionAllowed
			? $this->resolveCompletedSignersOfEmployeeDocuments($documentsById)
			: []
		;

		$targets = [];
		/** @var Member $member */
		foreach ($members as $member)
		{
			if ($member->id === null)
			{
				continue;
			}

			$document = $documentsById[$member->documentId] ?? null;
			if ($document === null)
			{
				continue;
			}

			if (!$substitutionAllowed || !$this->isSubstitutable($document, $member))
			{
				$targets[$member->id] = self::ownTargetOf($member);

				continue;
			}

			$signer = $signerByDocumentId[$document->id] ?? null;
			$targets[$member->id] = $signer === null
				// No signing to speak about: the row behaves the way it does today.
				? new AnnulmentTarget((string)$member->uid, false, false)
				: new AnnulmentTarget((string)$signer->uid, $signer->annulled, true)
			;
		}

		return $targets;
	}

	private function isSubstitutable(Document $document, Member $member): bool
	{
		return $document->initiatedByType->isEmployee() && $member->role === Role::ASSIGNEE;
	}

	/**
	 * One query for the whole page, and none at all when no document on it was initiated
	 * by an employee.
	 *
	 * Several completed signers of one document give an ambiguous target, so the lowest
	 * id wins (Q-1): the choice must not depend on the order the rows come back in, or
	 * the mark and the action address could disagree between the screen, the export and
	 * a reload of the same page.
	 *
	 * @param array<int, Document> $documentsById
	 * @return array<int, Member> document id => target signer
	 */
	private function resolveCompletedSignersOfEmployeeDocuments(array $documentsById): array
	{
		$employeeDocumentIds = [];
		foreach ($documentsById as $document)
		{
			if ($document->id !== null && $document->initiatedByType->isEmployee())
			{
				$employeeDocumentIds[] = $document->id;
			}
		}

		if ($employeeDocumentIds === [])
		{
			return [];
		}

		$signerByDocumentId = [];
		foreach ($this->memberRepository->listSignersByDocumentIds($employeeDocumentIds) as $signer)
		{
			if ($signer->status !== MemberStatus::DONE || $signer->id === null)
			{
				continue;
			}

			$known = $signerByDocumentId[$signer->documentId] ?? null;
			if ($known === null || $signer->id < $known->id)
			{
				$signerByDocumentId[$signer->documentId] = $signer;
			}
		}

		return $signerByDocumentId;
	}

	private static function ownTargetOf(Member $member): AnnulmentTarget
	{
		return new AnnulmentTarget(
			(string)$member->uid,
			$member->annulled,
			$member->role === Role::SIGNER && $member->status === MemberStatus::DONE,
		);
	}
}
