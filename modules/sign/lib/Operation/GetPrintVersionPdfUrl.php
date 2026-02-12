<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Sign\Service;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository;
use Bitrix\Main;
use Bitrix\Sign\Contract;

class GetPrintVersionPdfUrl implements Contract\Operation
{
	public ?bool $ready = null;
	public ?string $url = null;

	public function __construct(
		private string $documentUid,
		private string $memberUid,
		private ?Repository\MemberRepository $memberRepository = null,
		private ?Repository\DocumentRepository $documentRepository = null,
		private ?Service\Sign\DocumentService $documentService = null,
	)
	{
		$this->memberRepository ??= Service\Container::instance()->getMemberRepository();
		$this->documentRepository ??= Service\Container::instance()->getDocumentRepository();
		$this->documentService ??= Service\Container::instance()->getDocumentService();
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();

		$document = Service\Container::instance()->getDocumentRepository()->getByHash($this->documentUid);
		if (!$document)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$member = Service\Container::instance()->getMemberRepository()->getByUid($this->memberUid);

		if (!$member || $member->documentId !== $document->id)
		{
			return $result->addError(new Main\Error('Member not found'));
		}

		$request = new Item\Api\Document\PrintVersionLoadRequest($document->uid, $member->uid);

		$apiLoad = Service\Container::instance()->getSignedFileLoadService();
		$response = $apiLoad->loadPrintVersion($request);
		if (!$response->isSuccess())
		{
			return $result->addErrors($response->getErrors());
		}

		$this->ready = $response->ready;
		$this->url = $response->file?->url;

		return $result;
	}


}