<?php

namespace Bitrix\Sign\Operation\Document\Template;

use Bitrix\Main\Loader;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item\Document\Template;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Operation\Document\Template\Onboarding\GetOrCreateCompanyForDemoSigning;
use Bitrix\Sign\Repository\Document\TemplateRepository;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Result\Service\Sign\Document\UpdateTemplateResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\ProviderCode;
use Bitrix\Sign\Type\Template\Status;
use Bitrix\Sign\Type\Template\Visibility;

class CompleteOnboardingTemplateFilling implements Operation
{
	private readonly DocumentService $documentService;
	private readonly TemplateRepository $templateRepository;
	private readonly MemberService $memberService;

	public function __construct(
		private readonly Template $template,
		private readonly int $currentUserId,
		?DocumentService $documentService = null,
		?TemplateRepository $templateRepository = null,
		?MemberService $memberService = null,
	)
	{
		$container = Container::instance();
		$this->documentService = $documentService ?? $container->getDocumentService();
		$this->templateRepository = $templateRepository ?? $container->getDocumentTemplateRepository();
		$this->memberService = $memberService ?? $container->getMemberService();
	}

	public function launch(): \Bitrix\Main\Result
	{
		if (!Loader::includeModule('crm'))
		{
			return Result::createByErrorMessage('Module crm not installed');
		}

		$document = $this->documentService->getByTemplateId((int)$this->template->id);
		if (!$document || $document->uid === null)
		{
			return Result::createByErrorMessage('Document not found');
		}
		$this->hiddenTemplate();

		if ($this->currentUserId < 1)
		{
			return Result::createByErrorMessage('Current user not found');
		}

		$getCompanyOperation = new GetOrCreateCompanyForDemoSigning($this->currentUserId);
		$getCompanyResult = $getCompanyOperation->launch();
		if (!$getCompanyResult->isSuccess())
		{
			return $getCompanyResult;
		}
		$companyUid = $getCompanyOperation->getCompanyUid();
		$companyEntityId = $getCompanyOperation->getCompanyEntityId();

		if ($companyUid === null || $companyEntityId === null)
		{
			return Result::createByErrorMessage('Company not found');
		}

		$modifyCompanyResult = $this->documentService->modifyCompany($document->uid, $companyUid, $companyEntityId);
		if (!$modifyCompanyResult->isSuccess())
		{
			return $modifyCompanyResult->addErrors($modifyCompanyResult->getErrors());
		}

		$modifyProvider = $this->documentService->modifyProviderCode($document, ProviderCode::SES_RU_EXPRESS);
		if (!$modifyProvider->isSuccess())
		{
			return Result::createByErrorMessage('Modify provider error');
		}

		$assignee = $this->memberService->makeAssigneeByDocumentAndEntityId($document, $companyEntityId);
		$setupMembersResult = (new SetupTemplateMembers(
			document: $document,
			sendFromUserId: $this->currentUserId,
			representativeUserId: $this->currentUserId,
			memberList: new MemberCollection($assignee),
			hasSetupSigners: false,
		))->launch();
		if (!$setupMembersResult->isSuccess())
		{
			return $setupMembersResult->addErrors($setupMembersResult->getErrors());
		}

		$updateTemplateResult = $this->updateTemplateStatusAndVisibility();
		if (!$updateTemplateResult->isSuccess())
		{
			return Result::createByErrorMessage('Update template status and visibility error');
		}

		return new UpdateTemplateResult($this->template, $companyEntityId);
	}

	private function updateTemplateStatusAndVisibility(): \Bitrix\Main\Result
	{
		$this->template->status = Status::COMPLETED;
		$this->template->visibility = Visibility::VISIBLE;

		return $this->templateRepository->update($this->template);
	}

	private function hiddenTemplate(): void
	{
		$this->template->hidden = true;

		$this->templateRepository->update($this->template);
	}
}