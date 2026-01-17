<?php

namespace Bitrix\Crm\Controller\Recurring;

use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Communications;
use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Multifield\Type\Email;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Sender;
use CCrmMailTemplate;
use CCrmOwnerType;

class Mail extends Base
{
	public function getConfigAction(int $entityTypeId, ?int $entityId): array
	{
		if (!CCrmOwnerType::isCorrectEntityTypeId($entityTypeId))
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError($entityTypeId));

			return [];
		}

		if ($entityId > 0)
		{
			$canRead = Container::getInstance()->getUserPermissions()->item()->canRead($entityTypeId, $entityId);
		}
		else
		{
			$canRead = Container::getInstance()->getUserPermissions()->entityType()->canReadItems($entityTypeId);
		}

		if (!$canRead)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return [];
		}

		if (!Container::getInstance()->getFactory($entityTypeId)?->isRecurringEnabled())
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError());

			return [];
		}

		if (!Loader::includeModule('documentgenerator'))
		{
			$this->addError(ErrorCode::getModuleNotInstalledError('documentgenerator'));

			return [];
		}

		$documentButton = DocumentGeneratorManager::getInstance()->getDocumentButtonParameters(
			DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvider($entityTypeId),
			$entityId,
		);

		$itemIdentifier = new ItemIdentifier($entityTypeId, 1);

		return [
			'senders' => $this->getSenders(),
			'templates' => $this->getTemplates($entityTypeId),
			'documents' => $this->getDocuments($entityTypeId, $entityId),
			'communications' => $this->getCommunications($entityTypeId, $entityId),
			'documentUrl' => $documentButton['templateListUrl'],
		];
	}

	public function bindClientAction(Factory $factory, Item $entity, int $clientId, int $clientTypeId): ?array
	{
		$clientIdentifier = new ItemIdentifier($clientTypeId, $clientId);
		$clientBinder = Container::getInstance()->getClientBinder();
		$result = $clientBinder->bind($factory, $entity, $clientIdentifier);

		if ($result->isSuccess())
		{
			return [
				'communications' => $this->getCommunications($factory->getEntityTypeId(), $entity->getId()),
			];
		}

		$this->addErrors($result->getErrors());

		return null;
	}

	private function getUserId(): int
	{
		return $this->getCurrentUser()?->getId() ?? Container::getInstance()->getContext()->getUserId();
	}

	private function getSenders(): array
	{
		$mailBoxes = Sender::prepareUserMailboxes($this->getUserId());

		$result = [];
		foreach ($mailBoxes as $mailBox)
		{
			if ($mailBox['type'] === 'sender' || $mailBox['type'] === 'mailboxSender')
			{
				$result[] = $mailBox;
			}
		}

		return $result;
	}

	private function getTemplates(int $entityTypeId): array
	{
		$mailList = [];
		$mailTemplateData = CCrmMailTemplate::getUserAvailableTemplatesList($entityTypeId, $this->getUserId());

		while ($template = $mailTemplateData->Fetch())
		{
			$mailList[] = [
				'id' => $template['ID'],
				'title' => $template['TITLE'],
			];
		}

		return $mailList;
	}

	private function getDocuments(int $entityTypeId, ?int $entityId = null): array
	{
		$documentGeneratorManager = DocumentGeneratorManager::getInstance();

		if ($entityId > 0)
		{
			$templates = $documentGeneratorManager->getTemplatesByIdentifier(new ItemIdentifier($entityTypeId, $entityId));
		}
		else
		{
			$templates = $documentGeneratorManager->getTemplatesByEntityTypeId($entityTypeId);
		}

		return array_map(static fn ($template) => [
			'id' => $template->getId(),
			'title' => $template->getTitle(),
		], $templates);
	}

	private function getCommunications(int $entityTypeId, ?int $entityId = null): array
	{
		return (new Communications($entityTypeId, $entityId))->get(Email::ID);
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new Scope(Scope::NOT_REST);

		return $filters;
	}
}
