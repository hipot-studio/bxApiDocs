<?php

namespace Bitrix\Sign\Integration\Ui\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Factory\Access\AccessibleItemFactory;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\DocumentCollection;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\UI\EntitySelector;
use Bitrix\UI\EntitySelector\Dialog;

class SignRecentDocumentProvider extends EntitySelector\BaseProvider
{
	private AccessController $accessController;
	private DocumentRepository $documentRepository;
	private DocumentService $documentService;
	private MemberRepository $memberRepository;
	private AccessibleItemFactory $accessibleItemFactory;

	public function __construct(array $options = [])
	{
		parent::__construct();
		$this->accessController = new AccessController(CurrentUser::get()->getId());
		$this->documentRepository = Container::instance()->getDocumentRepository();
		$this->documentService = Container::instance()->getDocumentService();
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->accessibleItemFactory = Container::instance()->getAccessibleItemFactory();
	}

	public function isAvailable(): bool
	{
		$storage = Storage::instance();
		return
			$storage->isB2eAvailable()
			&& Feature::instance()->isDocumentsInSignersSelectorEnabled()
		;
	}

	/**
	 * @param array $ids
	 *
	 * @return EntitySelector\Item[]
	 */
	public function getItems(array $ids) : array
	{
		$documents = $this->documentRepository->listByIds($ids);
		return $this->prepareItems($documents);
	}

	public function fillDialog(Dialog $dialog): void
	{
		$documents = $this->documentRepository->listLastB2eNotInStatus(10, [
			DocumentStatus::NEW,
			DocumentStatus::UPLOADED,
		]);

		$dialog->addRecentItems($this->prepareItems($documents));
	}

	private function prepareItems(DocumentCollection $documentCollection): array
	{
		$items = [];
		foreach ($documentCollection as $document)
		{
			$accessibleItem = $this->accessibleItemFactory->createFromItem($document);
			if ($this->accessController->check(ActionDictionary::ACTION_B2E_DOCUMENT_READ, $accessibleItem))
			{
				$items[] = $this->prepareItem($document);
			}
		}
		return $items;
	}

	private function prepareItem(Document $document): EntitySelector\Item
	{
		$documentTitle = $this->documentService->getComposedTitleByDocument($document);

		$membersCount = $this->memberRepository->countMembersByDocumentIdAndRoleAndStatus($document->id);

		return new EntitySelector\Item([
			'id' => $document->id,
			'entityId' => 'sign-document',
			'title' => $documentTitle,
			'subtitle' => Loc::getMessage(
				'SIGN_INTEGRATION_UI_ENTITYSELECTOR_SIGNRECENTDOCUMENTPROVIDER_SIGNERS_COUNT',
				['#COUNT#' => $membersCount],
			),
			'searchable' => true,
			'hidden' => false,
			'tabs' => [
				'sign-document',
			],
			'customData' => [],
		]);
	}
}