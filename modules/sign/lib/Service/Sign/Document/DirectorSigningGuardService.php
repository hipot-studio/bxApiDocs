<?php

namespace Bitrix\Sign\Service\Sign\Document;

use Bitrix\Main;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\Member\SigningMethod;
use Bitrix\Sign\Type\ProviderCode;

/**
 * Guards the goskey signing method of the company representative on the way to signing.
 *
 * What is guarded here is the shape of the choice: a representative who is a person of this company,
 * under the only provider that has a head-signing branch. Whether the branch is open for the portal
 * is decided by the signing service, which owns that rollout by client id and refuses the document
 * itself. Everything else — ukep, a member with no method, a document without a representative
 * member — passes untouched.
 */
class DirectorSigningGuardService
{
	public const ERROR_CODE_NOT_ALLOWED = 'SIGNING_METHOD_PERMISSION_REQUIRED';

	private readonly MemberRepository $memberRepository;

	public function __construct(?MemberRepository $memberRepository = null)
	{
		$this->memberRepository = $memberRepository ?? Service\Container::instance()->getMemberRepository();
	}

	public function check(Item\Document $document): Main\Result
	{
		$result = new Main\Result();
		if ($document->id === null)
		{
			return $result;
		}

		$representative = $this->memberRepository->getByDocumentIdWithRole($document->id, Role::ASSIGNEE);
		if ($representative === null || $representative->signingMethod !== SigningMethod::GOS_KEY)
		{
			return $result;
		}

		// A role representative keeps the role id in representativeId, so there is nobody to sign.
		if ($representative->entityType !== EntityType::COMPANY)
		{
			return $result->addError($this->notAllowedError());
		}

		// The method belongs to the goskey-lite provider alone: no other provider has a head-signing
		// branch, so the stored method is either a leftover of a changed provider or was never chosen
		// through the wizard.
		if ($document->providerCode !== ProviderCode::GOS_KEY_LITE)
		{
			return $result->addError($this->notAllowedError());
		}

		return $result;
	}

	private function notAllowedError(): Main\Error
	{
		return new Main\Error(
			Main\Localization\Loc::getMessage('SIGN_SERVICE_DOCUMENT_DIRECTOR_SIGNING_NOT_ALLOWED'),
			self::ERROR_CODE_NOT_ALLOWED,
		);
	}
}
