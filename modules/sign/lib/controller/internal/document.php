<?php

namespace Bitrix\Sign\Controller\Internal;

use Bitrix\Main;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Document as DocumentCore;
use Bitrix\Sign\Helper\IterationHelper;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Api\Document\Signing\ResendMessageRequest;
use Bitrix\Sign\Item\Api\Document\Signing\SendInviteRequest;
use Bitrix\Sign\Proxy;
use Bitrix\Sign\Service;
use Bitrix\Sign\Type\Document\Version;
use Bitrix\Sign\Type\DocumentScenario;

class Document extends \Bitrix\Sign\Controller\Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new \Bitrix\Main\Engine\ActionFilter\Authentication(),
			new \Bitrix\Main\Engine\ActionFilter\Csrf(),
			new \Bitrix\Sign\Controller\ActionFilter\Extranet(),
		];
	}

	/**
	 * @param string $documentId
	 * @param string|null $memberHash Member uid: the timeline sends it under the legacy `hash` name.
	 * @todo check usages, timeline?
	 */
	public function resendFileAction(string $documentId, ?string $memberHash = null)
	{
		$documentItem = Service\Container::instance()->getDocumentRepository()->getById((int)$documentId);
		if ($documentItem === null || !$this->canCurrentUserResendDocument($documentItem))
		{
			$this->addError($this->createAccessDeniedError());

			return;
		}

		if (($documentItem->version ?? Version::V1) < Version::V2)
		{
			return $this->resendFileByLegacyProxy((int)$documentId, $memberHash);
		}

		$member = $this->getDocumentMember($documentItem, (string)$memberHash);
		if ($member === null)
		{
			$this->addError($this->createAccessDeniedError());

			return;
		}

		$response = $this->resendToMember($documentItem, $member);
		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());
		}
	}

	/** The uid goes into the path of the outgoing call, so only a stored member of this document is accepted. */
	private function getDocumentMember(Item\Document $document, string $memberUid): ?Item\Member
	{
		$member = Service\Container::instance()->getMemberRepository()->getByUid($memberUid);
		if ($member === null || $member->documentId !== $document->id)
		{
			return null;
		}

		return $member;
	}

	/** The resend message path excludes B2E both in sign and in the safe service, so B2E is served by the invite. */
	private function resendToMember(Item\Document $document, Item\Member $member): Item\Api\Response
	{
		$signingService = Service\Container::instance()->getApiDocumentSigningService();
		$documentUid = (string)$document->uid;
		$memberUid = (string)$member->uid;

		return DocumentScenario::isB2EScenario($document->scenario)
			? $signingService->sendInvite(new SendInviteRequest($documentUid, $memberUid))
			: $signingService->resendMessage(new ResendMessageRequest($documentUid, $memberUid))
		;
	}

	private function resendFileByLegacyProxy(int $documentId, ?string $memberHash): mixed
	{
		$document = DocumentCore::getById($documentId);
		if (!$document)
		{
			return null;
		}

		$member = $document->getMemberByHash((string)$memberHash);
		if ($member === null)
		{
			$this->addError($this->createAccessDeniedError());

			return null;
		}

		$result = Proxy::sendCommand('document.resend', [
			'hash' => $document->getHash(),
			'members' => [$member->toArray()],
		]);

		return $result ?: null;
	}

	private function createAccessDeniedError(): Main\Error
	{
		return new Main\Error(
			Main\Localization\Loc::getMessage('SIGN_CONTROLLER_INTERNAL_DOCUMENT_ERROR_ACCESS_DENIED'),
			'ACCESS_DENIED',
		);
	}

	protected function getAccessController(): AccessController
	{
		return new AccessController((int)$this->getCurrentUser()?->getId());
	}

	/** Resending a file is available to whoever may read the document as well as to whoever may edit it. */
	private function canCurrentUserResendDocument(Item\Document $document): bool
	{
		$accessActions = DocumentScenario::isB2EScenario($document->scenario)
			? [ActionDictionary::ACTION_B2E_DOCUMENT_READ, ActionDictionary::ACTION_B2E_DOCUMENT_EDIT]
			: [ActionDictionary::ACTION_DOCUMENT_READ, ActionDictionary::ACTION_DOCUMENT_EDIT]
		;

		$accessController = $this->getAccessController();

		return IterationHelper::any(
			$accessActions,
			static fn(string $action): bool => $accessController->checkByItem($action, $document),
		);
	}
}
