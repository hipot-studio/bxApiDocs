<?php

namespace Bitrix\Sign\Controllers\V1\B2e\Member;

use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute;
use Bitrix\Sign\FeatureResolver;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Type\Access\AccessibleItemType;
use Bitrix\Sign\Type\Member\Notification\ReminderType;
use Bitrix\Sign\Type\Member\Role;

final class Reminder extends Controller
{
	#[Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT, AccessibleItemType::DOCUMENT, 'documentUid')]
	public function setAction(string $documentUid, string $memberRole, string $type): array
	{
		$reminderType = ReminderType::tryFrom($type);
		if ($reminderType === null)
		{
			$this->addErrorByMessage("Invalid reminder type: {$type}");

			return [];
		}
		if (!Role::isValid($memberRole))
		{
			$this->addErrorByMessage("Invalid member role: {$memberRole}");

			return [];
		}

		$document = $this->container->getDocumentRepository()->getByUid($documentUid);
		if ($document === null)
		{
			$this->addErrorByMessage("Document not found: {$documentUid}");

			return [];
		}
		$result = (new Operation\Member\Reminder\Set($document, $memberRole, $reminderType))->launch();
		$this->addErrorsFromResult($result);

		return [];
	}

	#[Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_READ, AccessibleItemType::DOCUMENT, 'documentUid')]
	public function sendAction(string $documentUid, #[ElementsType(Type::String)] array $memberUids): array
	{
		if (!FeatureResolver::instance()->released('kedoSendManualReminder'))
		{
			return [];
		}

		$documentService = $this->container->getDocumentService();
		$document = $documentService->getByUid($documentUid);

		$logger = $this->container->getLogger('Controller');
		if ($document === null || $document->id === null)
		{
			$logger->warning(
				'Document not found. UID: {documentUid}',
				[
					'documentUid' => $documentUid,
				]
			);

			return [];
		}

		$result = (new Operation\Member\Reminder\SendManual(
			$document,
			$memberUids,
		))->launch();

		$this->addErrorsFromResult($result);

		$data = $result->getData();

		if (!array_key_exists('documentTitle', $data))
		{
			$data['documentTitle'] = $documentService->getComposedTitleByDocument($document);
		}

		return $data;
	}
}
