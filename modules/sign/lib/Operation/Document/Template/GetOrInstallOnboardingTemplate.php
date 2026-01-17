<?php

namespace Bitrix\Sign\Operation\Document\Template;

use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Document\Template;
use Bitrix\Sign\Repository\BlankRepository;
use Bitrix\Sign\Repository\Document\TemplateRepository;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Result\Service\Sign\Document\CreateTemplateResult;
use Bitrix\Sign\Service\Api\B2e\ProviderCodeService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\MyCompanyService;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Type\Template\Status;

class GetOrInstallOnboardingTemplate implements Operation
{
	private readonly TemplateRepository $templateRepository;
	private readonly DocumentService $documentService;
	private readonly BlankRepository $blankRepository;
	private readonly MyCompanyService $myCompanyService;
	private readonly ProviderCodeService $providerCodeService;
	private readonly Storage $storage;

	public function __construct(
		private readonly int $currentUserId,
		?TemplateRepository $templateRepository = null,
		?DocumentService $documentService = null,
		?BlankRepository $blankRepository = null,
		?MyCompanyService $myCompanyService = null,
		?ProviderCodeService $providerCodeService = null,
		?Storage $storage = null,
	)
	{
		$container = Container::instance();
		$this->templateRepository = $templateRepository ?? $container->getDocumentTemplateRepository();
		$this->documentService = $documentService ?? $container->getDocumentService();
		$this->blankRepository = $blankRepository ?? $container->getBlankRepository();
		$this->myCompanyService = $myCompanyService ?? $container->getCrmMyCompanyService();
		$this->providerCodeService = $providerCodeService ?? $container->getApiProviderCodeService();
		$this->storage = $storage ?? Storage::instance();
	}

	public function launch(): \Bitrix\Main\Result|CreateTemplateResult
	{
		return $this->getOrInstallOnboardingTemplate();
	}

	/**
	 * @return \Bitrix\Main\Result|CreateTemplateResult
	 */
	private function getOrInstallOnboardingTemplate(): \Bitrix\Main\Result|CreateTemplateResult
	{
		$templateForOnboarding = $this->getOnboardingTemplate();
		if (!$templateForOnboarding)
		{
			$result = (new InstallOnboardingTemplate($this->currentUserId))->launch();
			if (!$result->isSuccess())
			{
				return $result;
			}

			$templateForOnboarding = $this->getOnboardingTemplate();
			if (!$templateForOnboarding)
			{
				return Result::createByErrorMessage('Template not found');
			}
		}

		if (!$this->isTemplateReadyForSigning($templateForOnboarding))
		{
			$result = (new CompleteOnboardingTemplateFilling($templateForOnboarding, $this->currentUserId))->launch();
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new CreateTemplateResult($templateForOnboarding);
	}

	private function isTemplateReadyForSigning(Template $template): bool
	{
		if ($template->status !== Status::COMPLETED)
		{
			return false;
		}

		$document = $this->documentService->getByTemplateId((int)$template->id);

		if (!$document)
		{
			return false;
		}

		if (!$this->checkTemplateCompanyExist($document))
		{
			return false;
		}

		if (!$this->checkTemplateCompanyRegistered($document))
		{
			return false;
		}

		return true;
	}

	private function checkTemplateCompanyExist(Document $document): bool
	{
		$companyEntityId = $document->companyEntityId;

		if ((int)$companyEntityId <= 0)
		{
			return false;
		}

		$name = $this->myCompanyService->getCompanyName($companyEntityId);

		if ($name === null)
		{
			return false;
		}

		return true;
	}

	private function checkTemplateCompanyRegistered(Document $document): bool
	{
		$companyUid = $document->companyUid;

		if ($companyUid === null)
		{
			return false;
		}

		$providerCode = $this->providerCodeService->loadProviderCode($companyUid);

		return $providerCode !== null;
	}

	private function getOnboardingTemplate(): ?Template
	{
		$templateForOnboarding = null;
		$onboardingTemplateTitle = $this->getOnboardingTemplateTitle();
		$templatesByOnboardingName = $this->templateRepository->getHiddenTemplatesByTitle($onboardingTemplateTitle);
		foreach ($templatesByOnboardingName as $template)
		{
			$document = $this->documentService->getByTemplateId($template->id);
			if (!$document)
			{
				return $templateForOnboarding;
			}

			$blank = $this->blankRepository->getById((int)$document->blankId);
			if (!$blank)
			{
				return $templateForOnboarding;
			}

			$file = $blank->fileCollection->first();
			if (!$file)
			{
				return $templateForOnboarding;
			}

			if ($this->storage->getOnboardingTemplateSha256() === hash('sha256', $file->content->data))
			{
				$templateForOnboarding = $template;
			}
		}

		return $templateForOnboarding;
	}

	private function getOnboardingTemplateTitle(): string
	{
		return Loc::getMessage('SIGN_B2E_ONBOARDING_TEMPLATE_TITLE', [], 'ru') ?? '';
	}
}