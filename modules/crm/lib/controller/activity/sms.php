<?php

namespace Bitrix\Crm\Controller\Activity;

use Bitrix\Crm\Activity\Provider\Sms\MessageDto;
use Bitrix\Crm\Activity\Provider\Sms\PlaceholderContext;
use Bitrix\Crm\Activity\Provider\Sms\PlaceholderManager;
use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Format\PlaceholderFormatter;
use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Web\Json;
use Bitrix\MessageService\Controller\Sender;

class Sms extends Base
{
	public function sendAction(int $ownerTypeId, int $ownerId, array $params): void
	{
		$owner = new ItemIdentifier($ownerTypeId, $ownerId);

		$message = $this->createPreparedMessage($ownerTypeId, $ownerId, $params);
		if ($message === null)
		{
			return;
		}

		$sender = (new \Bitrix\Crm\Activity\Provider\Sms\Sender($owner, $message));

		if (isset($params['entityTypeId'], $params['entityId']))
		{
			$sender->setEntityIdentifier(new ItemIdentifier($params['entityTypeId'], $params['entityId']));
		}

		$paymentId = (int)($params['paymentId'] ?? 0);
		if ($paymentId > 0)
		{
			$sender->setPaymentId($paymentId);
		}

		$shipmentId = (int)($params['shipmentId'] ?? 0);
		if ($shipmentId > 0)
		{
			$sender->setShipmentId($shipmentId);
		}

		$source = (string)($params['source'] ?? '');
		if (!empty($source))
		{
			$sender->setSource($source);
		}

		if (isset($params['compilationProductIds']) && is_array($params['compilationProductIds']))
		{
			$productIds = $params['compilationProductIds'];
			Collection::normalizeArrayValuesByInt($productIds);

			$sender->setCompilationProductIds($productIds);
		}

		$result = $sender->send(true, false);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	private function createPreparedMessage(int $ownerTypeId, int $ownerId, array $params): ?MessageDto
	{
		$message = $this->createMessage($params);
		if ($message === null)
		{
			return null;
		}

		$message->body = $this->replacePlaceholdersIfNeeded($ownerTypeId, $ownerId, $message, $params);

		if (
			is_string($message->template)
			&& Json::validate($message->template)
			&& $this->isTemplateWithPlaceholders($params)
		)
		{
			/*
			 * Bitrix\MessageService\Providers\Edna\WhatsApp::getHSMContent use ['MESSAGE_HEADERS']['template']['text']
			 * from template, but not MESSAGE_BODY. Because It is assumed that in the future we will
			 * send templates with headers and footers, then we will need to redo
			 */

			$data = Json::decode($message->template);

			$data['text'] = $message->body;

			$message->template = Json::encode($data);
		}

		return $message;
	}

	private function createMessage(array $params): ?MessageDto
	{
		$messageFields = array_intersect_key(
			$params,
			[
				'senderId' => true,
				'from' => true,
				'to' => true,
				'body' => true,
				'templateOriginalId' => true,
			]
		);

		if (($messageFields['senderId'] ?? null) === NotificationsManager::getSenderCode())
		{
			$signedTemplate = $params['signedTemplate'] ?? null;
			if (is_string($signedTemplate))
			{
				$unsigned = NotificationsManager::unsignTemplate($signedTemplate);
				if (is_array($unsigned))
				{
					$messageFields['template'] = $unsigned['template'] ?? null;
					$messageFields['placeholders'] = $unsigned['placeholders'] ?? null;
				}
			}
		}
		else
		{
			$messageFields['template'] = $params['template'] ?? null;
		}

		$message = new MessageDto($messageFields);
		if ($message->hasValidationErrors())
		{
			$this->addErrors($message->getValidationErrors()->toArray());

			return null;
		}

		return $message;
	}

	private function replacePlaceholdersIfNeeded(int $ownerTypeId, int $ownerId, MessageDto $message, array $params): ?string
	{
		if ($message->body === null)
		{
			return null;
		}

		if (!$this->isReplacePlaceholders($params))
		{
			return $message->body;
		}

		$template = $message->body;
		if ($this->isPlaceholdersInDisplayFormat($params))
		{
			$template = PlaceholderFormatter::convertToExternalFormat($ownerTypeId, $template);
		}

		return DocumentGeneratorManager::getInstance()->replacePlaceholdersInText(
			$ownerTypeId,
			$ownerId,
			PlaceholderFormatter::escapeUnknownPlaceholdersInExternal($ownerTypeId, $template),
			' '
		) ?? $message->body;
	}

	private function isReplacePlaceholders(array $params): bool
	{
		if (!isset($params['isReplacePlaceholders']))
		{
			return false;
		}

		if ($params['isReplacePlaceholders'] !== 'true' && $params['isReplacePlaceholders'] !== true)
		{
			return false;
		}

		return DocumentGeneratorManager::getInstance()->isEnabled();
	}

	private function isPlaceholdersInDisplayFormat(array $params): bool
	{
		return (
			isset($params['isPlaceholdersInDisplayFormat'])
			&& (
				$params['isPlaceholdersInDisplayFormat'] === 'true'
				|| $params['isPlaceholdersInDisplayFormat'] === true
			)
		);
	}

	private function isTemplateWithPlaceholders(array $params): bool
	{
		return (
			isset($params['isTemplateWithPlaceholders'])
			&& (
				$params['isTemplateWithPlaceholders'] === 'true'
				|| $params['isTemplateWithPlaceholders'] === true
			)
		);
	}

	public function getTemplatesAction(string $senderId, array $context = null): array
	{
		if (!Loader::includeModule('messageservice'))
		{
			$this->addError(new Error(Loc::getMessage('CRM_ACTIVITY_SMS_MESSAGESERVICE_NOT_INSTALLED')));

			return [];
		}

		if (is_array($context))
		{
			$context['module'] = 'crm';
		}

		$result = $this->forward(
			Sender::class,
			'getTemplates',
			[
				'id' => $senderId,
				'context' => $context,
			]
		);

		$entityCategoryId = $context['entityCategoryId'] ?? null;
		if (!isset($context['entityTypeId']))
		{
			return $result;
		}

		if (!$this->canReadFilledPlaceholders((int)$context['entityTypeId'], $entityCategoryId))
		{
			return $result;
		}

		$placeholderManager = new PlaceholderManager();
		$ids = [];
		$templates = $result['templates'] ?? [];
		foreach ($templates as $template)
		{
			$ids[] = $template['ORIGINAL_ID'];
		}

		$placeholderContext = PlaceholderContext::createInstance($context['entityTypeId'], $entityCategoryId);
		$filledPlaceholders = $placeholderManager->getPlaceholders($ids, $placeholderContext);

		$result['templates'] = $this->appendTemplateFilledPlaceholders($templates, $filledPlaceholders);

		return $result;
	}

	private function canReadFilledPlaceholders(int $entityTypeId, ?int $entityCategoryId): bool
	{
		$perms = Container::getInstance()->getUserPermissions();

		if ($entityCategoryId === null)
		{
			return $perms->messageSender()->canSendFromItems($entityTypeId);
		}

		return $perms->messageSender()->canSendFromItemsInCategory($entityTypeId, $entityCategoryId);
	}

	private function appendTemplateFilledPlaceholders(array $templates, array $filledPlaceholders): array
	{
		foreach ($templates as &$template)
		{
			foreach ($filledPlaceholders as $filledPlaceholder)
			{
				if ($template['ORIGINAL_ID'] !== (int)$filledPlaceholder['TEMPLATE_ID'])
				{
					continue;
				}

				if (!isset($template['FILLED_PLACEHOLDERS']))
				{
					$template['FILLED_PLACEHOLDERS'] = [];
				}

				$template['FILLED_PLACEHOLDERS'][] = $filledPlaceholder;
			}
		}
		unset($template);

		return $templates;
	}

	public function getConfigAction(int $entityTypeId, int $entityId): array
	{
		return [
			'enable' => SmsManager::canUse(),
			'manageUrl' => SmsManager::getManageUrl(),
			'contactCenterUrl' => Container::getInstance()->getRouter()->getContactCenterUrl(),
			'canSendMessage' => SmsManager::canSendMessage(),
			'statusDescription' => SmsManager::getMessageStatusDescriptions(),
			'statusSemantics' => SmsManager::getMessageStatusSemantics(),
			'config' => $this->getConfig($entityTypeId, $entityId),
		];
	}

	private function getConfig(int $entityTypeId, int $entityId): array
	{
		$config = SmsManager::getEditorConfig($entityTypeId, $entityId);

		if (empty($config['communications']))
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);
			$item = $factory->getItem($entityId);

			if ($item && $item->hasField(Item::FIELD_NAME_CONTACT_BINDINGS))
			{
				$contacts = $item->getContacts();
				foreach ($contacts as $contact)
				{
					$config['communications'][] = [
						'entityId' => $contact->getId(),
						'entityTypeId' => \CCrmOwnerType::Contact,
						'caption' => $contact->getFormattedName(),
					];
				}

				if ($item->hasField(Item::FIELD_NAME_COMPANY))
				{
					$company = $item->getCompany();
					if ($company)
					{
						$config['communications'][] = [
							'entityId' => $company->getId(),
							'entityTypeId' => \CCrmOwnerType::Company,
							'caption' => $company->getTitle(),
						];
					}
				}
			}
		}

		$isMessageServiceInstalled = ModuleManager::isModuleInstalled('messageservice');

		foreach ($config['senders'] as &$sender)
		{
			$isTemplatesBased = ($sender['isTemplatesBased'] ?? false);
			$canUse = ($sender['canUse'] ?? false);
			$senderId = $sender['id'];

			if (
				$isTemplatesBased
				&& $canUse
				&& !empty($config['defaults'])
				&& $config['defaults']['senderId'] === $senderId
			)
			{
				if ($isMessageServiceInstalled)
				{
					$senderEntity = \Bitrix\MessageService\Sender\SmsManager::getSenderById($senderId);
					if ($senderEntity)
					{
						$sender['templates'] = $senderEntity->getTemplatesList();
					}
				}
				else
				{
					$config['defaults'] = null;
				}
			}
		}
		unset($sender);

		return $config;
	}
}
