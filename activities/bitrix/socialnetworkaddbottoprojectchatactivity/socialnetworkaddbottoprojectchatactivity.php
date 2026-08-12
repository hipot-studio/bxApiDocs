<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Public\Command\Project\Member\AddBotCommand;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\MemberCollection;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPSocialnetworkAddBotToProjectChatActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const PARAM_PROJECT_ID = 'ProjectId';
	private const PARAM_BOT_ID = 'BotId';
	private const PARAM_BOT_CODE = 'BotCode';

	protected static $requiredModules = ['socialnetwork', 'im', 'imbot'];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PARAM_PROJECT_ID => null,
			self::PARAM_BOT_ID => null,
			self::PARAM_BOT_CODE => null,
		];

		$this->setPropertiesTypes([
			self::PARAM_PROJECT_ID => [
				'Type' => FieldType::INT,
			],
			self::PARAM_BOT_ID => [
				'Type' => FieldType::INT,
			],
			self::PARAM_BOT_CODE => [
				'Type' => FieldType::STRING,
			],
		]);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected function checkProperties(): ErrorCollection
	{
		$errors = new ErrorCollection();

		$projectId = (int)$this->{self::PARAM_PROJECT_ID};
		if ($projectId <= 0)
		{
			$errors->setError(new Error(
				Loc::getMessage('SN_ADD_BOT_TO_PROJECT_CHAT_ERROR_INVALID_VALUE', [
					'#PROPERTY_NAME#' => self::getPropertyMapName(self::PARAM_PROJECT_ID),
				]) ?? '',
			));
		}

		$botId = $this->resolveBotId();
		if ($botId <= 0)
		{
			$errors->setError(new Error(
				Loc::getMessage('SN_ADD_BOT_TO_PROJECT_CHAT_ERROR_NO_BOT') ?? '',
			));
		}

		return $errors;
	}

	protected function internalExecute(): ErrorCollection
	{
		$errors = new ErrorCollection();

		$projectId = (int)$this->{self::PARAM_PROJECT_ID};
		$botId = $this->resolveBotId();

		$members = MemberCollection::mapFromArray([
			['id' => $botId, 'type' => MemberEntityType::User],
		]);

		try
		{
			$result = (new AddBotCommand($projectId, $members))->run();
		}
		catch (\Throwable $exception)
		{
			$errors->setError(new Error(Loc::getMessage('SN_ADD_BOT_TO_PROJECT_CHAT_ERROR_ADD_BOT', [
				'#ERROR#' => $exception->getMessage(),
			]) ?? ''));

			return $errors;
		}

		if (!$result->isSuccess())
		{
			$errors->setError(new Error(Loc::getMessage('SN_ADD_BOT_TO_PROJECT_CHAT_ERROR_ADD_BOT', [
				'#ERROR#' => implode('; ', $result->getErrorMessages()),
			]) ?? ''));
		}

		return $errors;
	}

	private function resolveBotId(): int
	{
		$botId = (int)$this->{self::PARAM_BOT_ID};
		if ($botId > 0)
		{
			return $botId;
		}

		$botCode = (string)$this->{self::PARAM_BOT_CODE};
		if ($botCode === '')
		{
			return 0;
		}

		return (int)BizprocBot::getBotIdByCode($botCode);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_PROJECT_ID => [
				'Name' => Loc::getMessage('SN_ADD_BOT_TO_PROJECT_CHAT_FIELD_PROJECT_ID'),
				'FieldName' => self::PARAM_PROJECT_ID,
				'Type' => FieldType::ENTITYSELECTOR,
				'Required' => true,
				'Settings' => [
					'entity' => ['id' => 'project'],
				],
			],
			self::PARAM_BOT_ID => [
				'Name' => Loc::getMessage('SN_ADD_BOT_TO_PROJECT_CHAT_FIELD_BOT_ID'),
				'FieldName' => self::PARAM_BOT_ID,
				'Type' => FieldType::SELECT,
				'Options' => self::getBizprocBotOptions(),
			],
			self::PARAM_BOT_CODE => [
				'Name' => Loc::getMessage('SN_ADD_BOT_TO_PROJECT_CHAT_FIELD_BOT_CODE'),
				'FieldName' => self::PARAM_BOT_CODE,
				'Type' => FieldType::STRING,
			],
		];
	}

	private static function getBizprocBotOptions(): array
	{
		if (!Loader::includeModule('imbot') || !Loader::includeModule('im'))
		{
			return [];
		}

		return BizprocBot::getBotNamesByIds();
	}

	private static function getPropertyMapName(string $propertyId): ?string
	{
		return self::getPropertiesDialogMap()[$propertyId]['Name'] ?? null;
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		$context = [];
		if ($dialog !== null)
		{
			$context = ['Properties' => $dialog->getCurrentValues()];
		}

		return static::getPropertiesMap([], $context);
	}

	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (empty($arTestProperties[self::PARAM_PROJECT_ID]))
		{
			$errors[] = [
				'message' => Loc::getMessage('SN_ADD_BOT_TO_PROJECT_CHAT_ERROR_PROJECT_EMPTY'),
			];
		}

		if (empty($arTestProperties[self::PARAM_BOT_ID]) && empty($arTestProperties[self::PARAM_BOT_CODE]))
		{
			$errors[] = [
				'message' => Loc::getMessage('SN_ADD_BOT_TO_PROJECT_CHAT_ERROR_BOT_ID_OR_CODE_EMPTY'),
			];
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}
}
