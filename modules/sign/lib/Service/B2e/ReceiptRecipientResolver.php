<?php

namespace Bitrix\Sign\Service\B2e;

use Bitrix\Sign\Item\B2e\ReceiptRecipient;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\B2e\ReceiptScenario;

/**
 * Resolves which user "received" a document and the name to display in a receipt mark.
 *
 * The HR role, if any, is expected to be already resolved into a concrete user id by the caller.
 * This service only picks the user and its represented name; it never sends messages.
 */
final class ReceiptRecipientResolver
{
	private MemberService $memberService;

	public function __construct(?MemberService $memberService = null)
	{
		$this->memberService = $memberService ?? Container::instance()->getMemberService();
	}

	public function resolveRecipient(
		Document $document,
		Member $assigneeMember,
		ReceiptScenario $scenario,
	): ?ReceiptRecipient
	{
		$userId = $scenario === ReceiptScenario::CompanyInitiated
			? $document->createdById
			: $this->resolveEmployeeInitiatedUserId($document, $assigneeMember)
		;

		if ($userId === null || $userId < 1)
		{
			return null;
		}

		$name = $this->memberService->getUserRepresentedName($userId);
		if (trim($name) === '')
		{
			return null;
		}

		return new ReceiptRecipient($userId, $name);
	}

	private function resolveEmployeeInitiatedUserId(Document $document, Member $assigneeMember): ?int
	{
		$userId = $this->memberService->getUserIdForMember($assigneeMember, $document);
		if ($userId === null || $userId < 1)
		{
			$userId = $document->representativeId;
		}

		return $userId;
	}
}
