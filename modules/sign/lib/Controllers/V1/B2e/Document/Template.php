<?php

namespace Bitrix\Sign\Controllers\V1\B2e\Document;

use Bitrix\Main\Error;
use Bitrix\Sign\Type;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Requisite\DefaultRequisite;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Security\Random;
use Bitrix\Sign\Connector;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Item\Document\TemplateCollection;
use Bitrix\Sign\Item\DocumentCollection;
use Bitrix\Sign\Item\Integration\Crm\MyCompanyCollection;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Operation\Document\Template\Send;
use Bitrix\Sign\Result\Operation\Document\Template\SendResult;
use Bitrix\Sign\Serializer\MasterFieldSerializer;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\Template\Status;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Type\Template\Visibility;


class Template extends Controller
{
	/**
	 * @return array<array{uid: string, title: string, company: array{id: int, name: string, taxId: string}, fields: array}>
	 */
	public function listAction(
		Main\Engine\CurrentUser $user,
	): array
	{
		$templates = $this->container->getDocumentTemplateRepository()
			->listWithStatusesAndVisibility([Status::COMPLETED], [Visibility::VISIBLE])
		;
		$documents = $this->container->getDocumentRepository()
			->listByTemplateIds($templates->getIdsWithoutNull())
		;
		$documentService = $this->container->getDocumentService();
		$companyIds = $documentService->listMyCompanyIdsForDocuments($documents);
		$lastUsedTemplateDocument = $documentService->getLastCreatedEmployeeDocumentFromDocuments($user->getId(), $documents);

		if (empty($companyIds))
		{
			return [];
		}

		$companies = $this->container->getCrmMyCompanyService()->listWithTaxIds(inIds: $companyIds);
		$result = [];
		$documents = $documents->sortByTemplateIdsDesc();
		foreach ($documents as $document)
		{
			$companyId = $companyIds[$document->id];
			$company = $companies->findById($companyId);
			if ($company === null)
			{
				continue;
			}
			$template = $templates->findById($document->templateId);
			if ($template === null)
			{
				continue;
			}

			$result[] = [
				'uid' => $template->uid,
				'title' => $template->title,
				'company' => [
					'name' => $company->name,
					'taxId' => $company->taxId,
					'id' => $company->id,
				],
				'isLastUsed' => $document->id === $lastUsedTemplateDocument?->createdFromDocumentId,
			];
		}

		return $result;
	}

	public function sendAction(
		string $uid,
		Main\Engine\CurrentUser $user,
		array $fields = [],
	): array
	{
		$template = Container::instance()->getDocumentTemplateRepository()->getByUid($uid);
		if ($template === null)
		{
			$this->addError(new Main\Error('Template not found'));

			return [];
		}

		$result = (new Send(
			template: $template,
			sendFromUserId: $user->getId(),
			fields: $fields,
		))->launch();
		if (!$result instanceof SendResult)
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		$employeeMember = $result->employeeMember;

		return [
			'employeeMember' => [
				'id' => $employeeMember->id,
				'uid' => $employeeMember->uid,
			],
		];
	}

	public function completeAction(string $uid): array
	{
		$templateRepository = Container::instance()->getDocumentTemplateRepository();
		$template = $templateRepository->getByUid($uid);
		if ($template === null)
		{
			$this->addErrorByMessage('Template not found');

			return [];
		}

		$result = (new Operation\Document\Template\Complete($template))->launch();
		$this->addErrorsFromResult($result);

		return [];
	}

	public function changeVisibilityAction(int $templateId, string $visibility): array
	{
		$visibility = Visibility::tryFrom($visibility);

		if ($visibility === null)
		{
			$this->addErrorByMessage('Incorrect visibility status');

			return [];
		}

		$templateRepository = Container::instance()->getDocumentTemplateRepository();

		$currentTemplate = $templateRepository->getById($templateId);
		$currentStatus = $currentTemplate?->status ?? Status::NEW;

		$isCurrentStatusNew = $currentStatus === Status::NEW;
		$isVisible = $visibility === Visibility::VISIBLE;

		$isStatusNewAndVisible = ($isCurrentStatusNew && $isVisible);
		if ($isStatusNewAndVisible)
		{
			$this->addErrorByMessage('Incorrect visibility status');

			return [];
		}

		$result = $templateRepository->updateVisibility($templateId, $visibility);
		if (!$result->isSuccess())
		{
			$this->addErrorsFromResult($result);
		}

		return [];
	}

	public function deleteAction(int $templateId): array
	{
		$template = Container::instance()->getDocumentTemplateRepository()->getById($templateId);
		if ($template === null)
		{
			$this->addErrorByMessage('Template not found');

			return [];
		}

		$result = (new Operation\Document\Template\Delete($template))->launch();
		$this->addErrorsFromResult($result);

		return [];
	}

	public function getFieldsAction(
		string $uid,
	): array
	{
		$template = Container::instance()->getDocumentTemplateRepository()->getByUid($uid);
		if ($template === null)
		{
			$this->addError(new Main\Error('Template not found'));

			return [];
		}

		$document = Container::instance()->getDocumentRepository()->getByTemplateId($template->id);
		if ($document === null)
		{
			$this->addError(new Main\Error('Document not found'));

			return [];
		}

		if (!DocumentScenario::isB2EScenario($document->scenario) || empty($document->companyUid))
		{
			$this->addError(new Main\Error('Incorrect document'));

			return [];
		}

		$factory = new \Bitrix\Sign\Factory\Field();
		$fields = $factory->createDocumentFutureSignerFields($document, CurrentUser::get()->getId());

		return [
			'fields' => (new MasterFieldSerializer())->serialize($fields),
		];
	}
}
