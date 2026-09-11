<?php

namespace Bitrix\Sign\Operation\Document\Template;

use Bitrix\Main;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\Document\TemplateFolderRelationRepository;
use Bitrix\Sign\Repository\Document\TemplateRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\Document\TemplateService;
use Bitrix\Sign\Service\Sign\PlaceholderBlockService;
use Bitrix\Sign\Type\DateTime;
use Bitrix\Sign\Type\Template\EntityType;
use Bitrix\Sign\Type\Template\Status;
use Bitrix\Sign\Type\Template\Visibility;

final class Complete implements Contract\Operation
{
	private readonly TemplateRepository $templateRepository;
	private readonly TemplateFolderRelationRepository $templateFolderRelationRepository;
	private readonly DocumentRepository $documentRepository;
	private readonly PlaceholderBlockService $placeholderBlockService;
	private readonly TemplateService $templateService;

	public function __construct(
		private readonly Item\Document\Template $template,
		?TemplateRepository $templateRepository = null,
		?TemplateFolderRelationRepository $templateFolderRelationRepository = null,
		?DocumentRepository $documentRepository = null,
		?PlaceholderBlockService $placeholderBlockService = null,
		?TemplateService $templateService = null,
	)
	{
		$container = Container::instance();
		$this->templateRepository = $templateRepository ?? $container->getDocumentTemplateRepository();
		$this->templateFolderRelationRepository = $templateFolderRelationRepository ?? $container->getTemplateFolderRelationRepository();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->placeholderBlockService = $placeholderBlockService ?? $container->getPlaceholderBlockService();
		$this->templateService = $templateService ?? $container->getDocumentTemplateService();
	}

	public function launch(): Main\Result
	{
		if ($this->template->id === null)
		{
			return (new Main\Result())->addError(new Main\Error('Template is not saved'));
		}

		$this->template->dateModify = new DateTime();
		$this->template->modifiedById = Main\Engine\CurrentUser::get()->getId();

		$updateParentResult = $this->templateFolderRelationRepository->updateParent(
			$this->template->folderId,
			[$this->template->id],
			EntityType::TEMPLATE,
		);

		if (!$updateParentResult->isSuccess())
		{
			return (new Main\Result())->addError(new Main\Error('Update parent id error'));
		}

		$document = $this->documentRepository->getByTemplateId($this->template->id);

		if ($document !== null)
		{
			$createPlaceholderBlocksResult = $this->placeholderBlockService->createBlocksForEmployeeTemplate($document);

			if (!$createPlaceholderBlocksResult->isSuccess())
			{
				return $createPlaceholderBlocksResult;
			}
		}

		if ($this->template->status !== Status::COMPLETED)
		{
			$this->template->status = Status::COMPLETED;
			$this->template->visibility = Visibility::VISIBLE;
		}

		$updateResult = $this->templateRepository->update($this->template);
		if (!$updateResult->isSuccess())
		{
			return $updateResult;
		}

		return $updateResult->addErrors($this->makeFolderVisible()->getErrors());
	}

	private function makeFolderVisible(): Main\Result
	{
		$folderId = $this->template->folderId ?? 0;
		if ($folderId < 1 || $this->template->visibility !== Visibility::VISIBLE)
		{
			return new Main\Result();
		}

		return $this->templateService->changeFolderVisibilityOnTemplateCompletion($folderId);
	}
}
