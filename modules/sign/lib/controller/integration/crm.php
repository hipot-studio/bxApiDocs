<?php

namespace Bitrix\Sign\Controller\Integration;

use Bitrix\Main;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Controller\Controller;
use Bitrix\Sign\File;
use Bitrix\Sign\Service;
use Bitrix\Sign\Type\DocumentScenario;

class Crm extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\Authentication(),
			new ActionFilter\Csrf(),
			new \Bitrix\Sign\Controller\ActionFilter\Extranet(),
		];
	}

	public function configureActions(): array
	{
		return [
			'getFormCode' => [
				'-prefilters' => [
					ActionFilter\Authentication::class,
					ActionFilter\Csrf::class,
					\Bitrix\Sign\Controller\ActionFilter\Extranet::class,
				],
			],
		];
	}

	/**
	 * Saves company signature.
	 * @param int $companyId Company id.
	 * @param array $signature Signature file content [name, content].
	 * @param int|null $documentId Document id. If specified, refresh signature in member.
	 * @return array|null
	 */
	public function saveCompanySignatureAction(int $companyId, array $signature, ?int $documentId = null): ?array
	{
		// Keep the check ahead of the file save and of the requisite write: a denial must leave nothing behind.
		if ($documentId && !$this->canCurrentUserEditDocument($documentId))
		{
			$this->addAccessDeniedError();

			return null;
		}

		$file = new File($signature);
		if ($file->isExist())
		{
			$file->setModule('crm');
			$file->save();

			$result = \Bitrix\Sign\Integration\CRM::saveCompanySignature($companyId, $file);
			if ($result)
			{
				if ($documentId)
				{
					$document = \Bitrix\Sign\Document::getById($documentId);
					if ($document && ($member = $document->getInitiatorMember()))
					{
						$file = new File($file->getPath());
						$member->setSignatureFile($file);
					}
				}

				return [
					'id' => $file->getId(),
					'src' => $file->getUriPath(),
				];
			}
			else
			{
				$file->unlink();
			}
		}

		return null;
	}

	/**
	 * Saves company stamp.
	 *
	 * @param int $companyId Company id.
	 * @param string $fileId uploader: serverId
	 * @param int|null $documentId Document id. If specified, refresh stamp in member.
	 * @return array|null
	 */
	public function saveCompanyStampAction(int $companyId, string $fileId, ?int $documentId = null): ?array
	{
		if ($documentId && !$this->canCurrentUserEditDocument($documentId))
		{
			$this->addAccessDeniedError();

			return null;
		}

		$fileController = new \Bitrix\Sign\Upload\StampUploadController();
		$uploader = new \Bitrix\UI\FileUploader\Uploader($fileController);
		$pendingFiles = $uploader->getPendingFiles([$fileId]);
		$file = $pendingFiles->get($fileId);

		if (!$file)
		{
			return null;
		}

		$stamp = new File($file->getFileId());

		if ($stamp->isExist())
		{
			$stamp->setModule('crm');
			$stamp->save();

			$result = \Bitrix\Sign\Integration\CRM::saveCompanyStamp($companyId, $stamp);
			if ($result)
			{
				if ($documentId)
				{
					$document = \Bitrix\Sign\Document::getById($documentId);
					if ($document && ($member = $document->getInitiatorMember()))
					{
						$stamp = new File($stamp->getPath());
						$member->setStampFile($stamp);
					}
				}

				return [
					'id' => $stamp->getId(),
					'src' => $stamp->getUriPath(),
				];
			}
			else
			{
				$stamp->unlink();
			}
		}

		return null;
	}

	/**
	 * Returns form html code.
	 * @param string $documentHash Document hash.
	 * @param string $memberHash Member hash.
	 * @return array|null
	 */
	public function getFormCodeAction(string $documentHash, string $memberHash): ?array
	{
		return \Bitrix\Sign\Integration\CRM::getFormCode($documentHash, $memberHash);
	}

	/**
	 * Refreshes entity number and returns new value.
	 * @return string|int|null
	 */
	public function refreshNumberInDocumentAction(int $documentId)
	{
		if (!$this->canCurrentUserEditDocument($documentId))
		{
			$this->addAccessDeniedError();

			return null;
		}

		$document = \Bitrix\Sign\Document::getById($documentId);
		if ($document)
		{
			return $document->refreshEntityNumber();

		}

		return null;
	}

	protected function getAccessController(): AccessController
	{
		return new AccessController((int)$this->getCurrentUser()?->getId());
	}

	/**
	 * The edit grant is asked for the loaded document, not for the id taken from the request.
	 */
	private function canCurrentUserEditDocument(int $documentId): bool
	{
		$document = Service\Container::instance()->getDocumentRepository()->getById($documentId);
		if ($document === null)
		{
			return false;
		}

		$accessAction = DocumentScenario::isB2EScenario($document->scenario)
			? ActionDictionary::ACTION_B2E_DOCUMENT_EDIT
			: ActionDictionary::ACTION_DOCUMENT_EDIT
		;

		return $this->getAccessController()->checkByItem($accessAction, $document);
	}

	/** A missing document answers exactly as a forbidden one: the response must not tell the two apart. */
	private function addAccessDeniedError(): void
	{
		$this->addError(new Main\Error(
			Main\Localization\Loc::getMessage('SIGN_CONTROLLER_INTEGRATION_CRM_ERROR_ACCESS_DENIED'),
			'ACCESS_DENIED',
		));
	}
}
