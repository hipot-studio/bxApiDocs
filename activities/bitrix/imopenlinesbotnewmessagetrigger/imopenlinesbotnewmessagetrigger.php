<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\ImBot\Bot\OpenLinesBizprocBot;
use Bitrix\ImBot\Integration\Im\Repository\OpenLinesBotRepository;
use Bitrix\ImOpenLines\Session;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Bizproc\Error;

final class CBPImOpenLinesBotNewMessageTrigger extends BaseTrigger
{
	private const PARAM_BOT_ID = 'botId';
	private const PARAM_BOT_CODE = 'botCode';

	private const RETURN_PARAM_MESSAGE = 'message';
	private const RETURN_PARAM_CHAT_ID = 'chatId';
	private const RETURN_PARAM_ACTUAL_BOT_ID = 'actualBotId';
	private const RETURN_PARAM_FROM_USER_ID = 'fromUserId';
	private const RETURN_PARAM_CRM_ASSOCIATED_ITEM = 'crmAssociatedItem';
	private const RETURN_PARAM_CRM_ASSOCIATED_CLIENT = 'crmAssociatedClient';
	private const RETURN_PARAM_CRM_ACTIVITY_ID = 'crmActivityId';
	private const RETURN_PARAM_CRM_ASSOCIATED_ITEM_ENTITY_TYPE_ID = 'crmAssociatedItemEntityTypeId';
	private const RETURN_PARAM_CRM_ASSOCIATED_ITEM_ID = 'crmAssociatedItemId';
	private const RETURN_PARAM_CRM_ASSOCIATED_CLIENT_ENTITY_TYPE_ID = 'crmAssociatedClientEntityTypeId';
	private const RETURN_PARAM_CRM_ASSOCIATED_CLIENT_ID = 'crmAssociatedClientId';
	private const RETURN_PARAM_IS_FIRST_MESSAGE = 'isFirstMessage';

	public function __construct(string $name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PARAM_BOT_ID => '',
			self::PARAM_BOT_CODE => '',

			self::RETURN_PARAM_MESSAGE => null,
			self::RETURN_PARAM_CHAT_ID => null,
			self::RETURN_PARAM_ACTUAL_BOT_ID => null,
			self::RETURN_PARAM_FROM_USER_ID => null,
			self::RETURN_PARAM_CRM_ASSOCIATED_ITEM => null,
			self::RETURN_PARAM_CRM_ASSOCIATED_CLIENT => null,
			self::RETURN_PARAM_CRM_ACTIVITY_ID => null,
			self::RETURN_PARAM_CRM_ASSOCIATED_ITEM_ENTITY_TYPE_ID => null,
			self::RETURN_PARAM_CRM_ASSOCIATED_ITEM_ID => null,
			self::RETURN_PARAM_CRM_ASSOCIATED_CLIENT_ENTITY_TYPE_ID => null,
			self::RETURN_PARAM_CRM_ASSOCIATED_CLIENT_ID => null,
			self::RETURN_PARAM_IS_FIRST_MESSAGE => null,
		];

		$this->SetPropertiesTypes([
			self::RETURN_PARAM_MESSAGE => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_CHAT_ID => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_ACTUAL_BOT_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_FROM_USER_ID => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_CRM_ASSOCIATED_ITEM => [
				'Type' => FieldType::DOCUMENT,
			],
			self::RETURN_PARAM_CRM_ASSOCIATED_CLIENT => [
				'Type' => FieldType::DOCUMENT,
			],
			self::RETURN_PARAM_CRM_ACTIVITY_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_CRM_ASSOCIATED_ITEM_ENTITY_TYPE_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_CRM_ASSOCIATED_ITEM_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_CRM_ASSOCIATED_CLIENT_ENTITY_TYPE_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_CRM_ASSOCIATED_CLIENT_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_IS_FIRST_MESSAGE => [
				'Type' => FieldType::BOOL,
			],
		]);
	}

	public function execute(): int
	{
		if (
			!$this->checkModuleIncluded('imopenlines')
			|| !$this->checkModuleIncluded('imbot')
			|| !$this->checkModuleIncluded('im')
		)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$botId = $this->getBotId() ?? $this->getBotIdByCode();
		if ($botId === null)
		{
			$this->trackError(Loc::getMessage('IMOL_BOT_NEW_MESSAGE_TRIGGER_ERROR_BOT_NOT_FOUND'));

			return CBPActivityExecutionStatus::Closed;
		}

		$context = $this->getRootActivity()->{CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];

		$fromUserId = $context['FROM_USER_ID'] ?? '';

		$this->{self::RETURN_PARAM_ACTUAL_BOT_ID} = $botId;
		$this->{self::RETURN_PARAM_MESSAGE} = (string)($context['MESSAGE'] ?? '');
		$this->{self::RETURN_PARAM_CHAT_ID} = ($context['DIALOG_ID'] ?? '');
		$this->{self::RETURN_PARAM_FROM_USER_ID} = "user_{$fromUserId}";
		$this->{self::RETURN_PARAM_IS_FIRST_MESSAGE} = $context['IS_CHAT_STARTED'] ?? false;

		$this->fillCrmReturns($context['CHAT_ENTITY_ID'] ?? '');

		return CBPActivityExecutionStatus::Closed;
	}

	private function fillCrmReturns(string $chatEntityId): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$session = new Session();
		$isSessionLoad = $session->load([
			'USER_CODE' => $chatEntityId,
		]);
		if (!$isSessionLoad)
		{
			return;
		}

		$activityId = (int)$session->getData('CRM_ACTIVITY_ID');
		if ($activityId <= 0)
		{
			return;
		}

		if (!CCrmActivity::Exists($activityId, false))
		{
			return;
		}

		$this->{self::RETURN_PARAM_CRM_ACTIVITY_ID} = $activityId;

		$bindings = CCrmActivity::GetBindings($activityId);
		if (empty($bindings))
		{
			return;
		}

		$clientEntityTypeIds = [
			CCrmOwnerType::Contact,
			CCrmOwnerType::Company,
		];

		$isNotClientFilter = static fn (ItemIdentifier $item) => !in_array($item->getEntityTypeId(), $clientEntityTypeIds, true);
		$associatedItem = $this->getFirstBinding($bindings, $isNotClientFilter);
		if ($associatedItem !== null)
		{
			$this->{self::RETURN_PARAM_CRM_ASSOCIATED_ITEM} = CCrmBizProcHelper::ResolveDocumentId(
				$associatedItem->getEntityTypeId(),
				$associatedItem->getEntityId(),
			);

			$this->{self::RETURN_PARAM_CRM_ASSOCIATED_ITEM_ENTITY_TYPE_ID} = $associatedItem->getEntityTypeId();
			$this->{self::RETURN_PARAM_CRM_ASSOCIATED_ITEM_ID} = $associatedItem->getEntityId();
		}

		$isClientFilter = static fn (ItemIdentifier $item) => in_array($item->getEntityTypeId(), $clientEntityTypeIds, true);
		$associatedClient = $this->getFirstBinding($bindings, $isClientFilter);
		if ($associatedClient !== null)
		{
			$this->{self::RETURN_PARAM_CRM_ASSOCIATED_CLIENT} = CCrmBizProcHelper::ResolveDocumentId(
				$associatedClient->getEntityTypeId(),
				$associatedClient->getEntityId(),
			);
			$this->{self::RETURN_PARAM_CRM_ASSOCIATED_CLIENT_ENTITY_TYPE_ID} = $associatedClient->getEntityTypeId();
			$this->{self::RETURN_PARAM_CRM_ASSOCIATED_CLIENT_ID} = $associatedClient->getEntityId();
		}
	}

	private function getBotId(): ?int
	{
		$botId = $this->{self::PARAM_BOT_ID};
		if (!is_numeric($botId) || (int)$botId <= 0)
		{
			return null;
		}

		$isExists = self::getOpenLinesBotRepository()?->isExists((int)$botId) ?? false;

		return $isExists ? (int)$botId : null;
	}

	private function getBotIdByCode(): ?int
	{
		$botCode = (string)$this->{self::PARAM_BOT_CODE};
		if (empty($botCode))
		{
			return null;
		}

		return self::getOpenLinesBotRepository()?->getByCode($botCode)?->getBotId();
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_BOT_ID => [
				'Name' => Loc::getMessage('IMOL_BOT_NEW_MESSAGE_TRIGGER_PARAM_BOT_ID'),
				'FieldName' => self::PARAM_BOT_ID,
				'Type' => FieldType::ENTITYSELECTOR,
				'Options' => self::getOpenLinesBots(),
			],
			self::PARAM_BOT_CODE => [
				'Name' => Loc::getMessage('IMOL_BOT_NEW_MESSAGE_TRIGGER_PARAM_BOT_CODE'),
				'FieldName' => self::PARAM_BOT_CODE,
				'Type' => FieldType::STRING,
			],
		];
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (empty($arTestProperties[self::PARAM_BOT_ID]) && empty($arTestProperties[self::PARAM_BOT_CODE]))
		{
			$errors[] = [
				'code' => 'NotExist',
				'message' => Loc::getMessage('IMOL_BOT_NEW_MESSAGE_TRIGGER_ERROR_BOT_ID_AND_BOT_CODE_EMPTY'),
			];
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		$botIdFromEvent = (int)$parameters->get('BOT_ID');
		if ($botIdFromEvent <= 0)
		{
			return Result::createError(
				new Error('Bot ID from event not numeric or less 0'),
			);
		}

		$actualBotId = $this->getBotId() ?? $this->getBotIdByCode();
		if ($actualBotId !== $botIdFromEvent)
		{
			return Result::createError(
				new Error('Bot ID from event not equals actual bot ID'),
			);
		}

		return Result::createOk();
	}

	private function checkModuleIncluded(string $module): bool
	{
		if (!Loader::includeModule($module))
		{
			$errorMessage = Loc::getMessage(
				'IMOL_BOT_NEW_MESSAGE_TRIGGER_ERROR_MODULE_NOT_INCLUDED',
				[
					'#MODULE#' => $module,
				],
			);

			$this->trackError($errorMessage);

			return false;
		}

		return true;
	}

	private static function getOpenLinesBots(): array
	{
		return self::getOpenLinesBotRepository()?->getNamesByClassMappedById(OpenLinesBizprocBot::class) ?? [];
	}

	private static function getOpenLinesBotRepository(): ?OpenLinesBotRepository
	{
		if (!Loader::includeModule('imbot'))
		{
			return null;
		}

		return ServiceLocator::getInstance()->get(OpenLinesBotRepository::class);
	}

	private function getFirstBinding(array $bindings, callable $filter): ?ItemIdentifier
	{
		foreach ($bindings as $binding)
		{
			$ownerTypeId = (int)($binding['OWNER_TYPE_ID'] ?? 0);
			if (!CCrmOwnerType::IsDefined($ownerTypeId))
			{
				continue;
			}

			$ownerId = (int)($binding['OWNER_ID'] ?? 0);
			if ($ownerId <= 0)
			{
				continue;
			}

			$itemIdentifier = ItemIdentifier::createByParams($ownerTypeId, $ownerId);
			if ($filter($itemIdentifier))
			{
				return $itemIdentifier;
			}
		}

		return null;
	}
}
