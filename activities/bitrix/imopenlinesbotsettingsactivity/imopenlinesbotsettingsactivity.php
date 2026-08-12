<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\ImBot\Bot\OpenLinesBizprocBot;
use Bitrix\ImOpenLines\Common;
use Bitrix\ImOpenLines\Config;
use Bitrix\ImOpenlines\Security\Permissions;
use Bitrix\ImOpenLines\V2\Queue\Queue;
use Bitrix\ImOpenLines\V2\Queue\QueueItem;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Error;

final class CBPImOpenLinesBotSettingsActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const PARAM_RESPONSIBLE_ID = 'responsibleId';
	private const PARAM_BOT_NAME = 'botName';
	private const PARAM_BOT_CODE = 'botCode';
	private const PARAM_QUEUES_TO_CONNECT = 'queuesToConnect';
	private const PARAM_BOT_JOIN = 'botJoin';
	private const PARAM_BOT_TIME = 'botTime';
	private const PARAM_BOT_LEFT = 'botLeft';

	private const RETURN_PARAM_BOT_ID = 'botId';
	private const RETURN_PARAM_ERRORS = 'errors';
	private const RETURN_PARAM_SUCCESS_QUEUE_CONNECTED_LIST = 'successQueueConnectedList';

	public function __construct(string $name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PARAM_RESPONSIBLE_ID => null,
			self::PARAM_BOT_NAME => null,
			self::PARAM_BOT_CODE => null,
			self::PARAM_QUEUES_TO_CONNECT => null,
			self::PARAM_BOT_JOIN => null,
			self::PARAM_BOT_TIME => null,
			self::PARAM_BOT_LEFT => null,

			self::RETURN_PARAM_BOT_ID => null,
			self::RETURN_PARAM_ERRORS => null,
			self::RETURN_PARAM_SUCCESS_QUEUE_CONNECTED_LIST => null,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_BOT_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_ERRORS => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_SUCCESS_QUEUE_CONNECTED_LIST => [
				'Type' => FieldType::STRING,
			],
		]);
	}

	public static function validateProperties($testProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$arErrors = [];

		$botJoin = $testProperties[self::PARAM_BOT_JOIN] ?? null;
		if (!self::isCorrectBotJoin($botJoin))
		{
			$arErrors[] = [
				'parameter' => self::PARAM_BOT_JOIN,
				'message' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_BOT_JOIN_INCORRECT'),
			];
		}

		$botTime = $testProperties[self::PARAM_BOT_TIME] ?? null;
		if (!self::isCorrectBotTime($botTime))
		{
			$arErrors[] = [
				'parameter' => self::PARAM_BOT_TIME,
				'message' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_BOT_TIME_INCORRECT'),
			];
		}

		$botLeft = $testProperties[self::PARAM_BOT_LEFT] ?? null;
		if (!self::isCorrectBotLeft($botLeft))
		{
			$arErrors[] = [
				'parameter' => self::PARAM_BOT_LEFT,
				'message' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_BOT_LEFT_INCORRECT'),
			];
		}

		return array_merge($arErrors, parent::validateProperties($testProperties, $user));
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_RESPONSIBLE_ID => [
				'Name' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_RESPONSIBLE_ID_NAME'),
				'FieldName' => self::PARAM_RESPONSIBLE_ID,
				'Type' => FieldType::USER,
				'Required' => true,
			],
			self::PARAM_BOT_NAME => [
				'Name' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_NAME_NAME'),
				'FieldName' => self::PARAM_BOT_NAME,
				'Type' => FieldType::STRING,
				'Required' => true,
			],
			self::PARAM_BOT_CODE => [
				'Name' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_CODE_NAME'),
				'FieldName' => self::PARAM_BOT_CODE,
				'Type' => FieldType::STRING,
			],
			self::PARAM_QUEUES_TO_CONNECT => [
				'Name' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_QUEUES_TO_CONNECT_NAME'),
				'FieldName' => self::PARAM_QUEUES_TO_CONNECT,
				'Type' => FieldType::ENTITYSELECTOR,
				'Multiple' => true,
				'AllowSelection' => true,
				'Settings' => [
					'useObjectResponse' => true,
					'dialogOptions' => [
						'entities' => [
							[
								'id' => 'imopenlines-queue',
								'dynamicLoad' => true,
								'dynamicSearch' => true,
							],
						],
					],
				],
			],
			self::PARAM_BOT_JOIN => [
				'Name' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_JOIN_NAME'),
				'FieldName' => self::PARAM_BOT_JOIN,
				'Type' => FieldType::SELECT,
				'AllowSelection' => false,
				'Options' => [
					'first' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_JOIN_OPTION_FIRST'),
					'always' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_JOIN_OPTION_ALWAYS'),
				],
			],
			self::PARAM_BOT_TIME => [
				'Name' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_TIME_NAME'),
				'FieldName' => self::PARAM_BOT_TIME,
				'Type' => FieldType::SELECT,
				'AllowSelection' => false,
				'Options' => [
					0 => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_TIME_OPTION_0'),
					1 => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_TIME_OPTION_1'),
					3 => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_TIME_OPTION_3'),
					5 => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_TIME_OPTION_5'),
					10 => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_TIME_OPTION_10'),
					15 => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_TIME_OPTION_15'),
					30 => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_TIME_OPTION_30'),
				],
			],
			self::PARAM_BOT_LEFT => [
				'Name' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_LEFT_NAME'),
				'FieldName' => self::PARAM_BOT_LEFT,
				'Type' => FieldType::SELECT,
				'AllowSelection' => false,
				'Options' => [
					'queue' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_LEFT_OPTION_QUEUE'),
					'close' => Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_PARAM_BOT_LEFT_OPTION_CLOSE'),
				],
			],
		];
	}

	/**
	 * @throws ArgumentException
	 */
	public function execute(): int
	{
		if (
			!$this->checkModuleIncluded('im')
			|| !$this->checkModuleIncluded('imopenlines')
			|| !$this->checkModuleIncluded('imbot')
		)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$registerOrUpdateResult = $this->registerOrUpdateBot();
		if (!$registerOrUpdateResult->isSuccess())
		{
			$this->fail($registerOrUpdateResult);

			return CBPActivityExecutionStatus::Closed;
		}

		$botId = $registerOrUpdateResult->getData()['botId'];
		$this->{self::RETURN_PARAM_BOT_ID} = $botId;

		$modifyResult = $this->modifyOpenLinesSettings($botId);
		if (!$modifyResult->isSuccess())
		{
			$this->fail($modifyResult);

			return CBPActivityExecutionStatus::Closed;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function registerOrUpdateBot(): Result
	{
		$result = new Result();

		$botName = $this->getBotName();
		if ($botName === null)
		{
			return $result
				->addError(
					new Error(Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_BOT_NAME_EMPTY')),
				);
		}

		$botId = OpenLinesBizprocBot::registerOrUpdate(
			$this->getBotCode(),
			$botName,
			(int)$this->getWorkflowTemplateId(),
		);

		if ($botId === null)
		{
			return $result
				->addError(
					new Error(Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_CANNOT_REGISTER_OR_UPDATE')),
				);
		}

		return $result
			->setData([
				'botId' => $botId,
			]);
	}

	/**
	 * @throws ArgumentException
	 */
	private function modifyOpenLinesSettings(int $botId): Result
	{
		$result = new Result();

		$responsibleId = $this->getResponsibleId();
		if ($responsibleId === null)
		{
			return $result
				->addError(
					new Error(Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_RESPONSIBLE_ID_EMPTY')),
				);
		}

		$permissions = Permissions::createWithUserId($responsibleId);
		if (!$permissions->canPerform(Permissions::ENTITY_LINES, Permissions::ACTION_MODIFY))
		{
			return $result
				->addError(
					new Error(Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_LINES_MODIFY_ACCESS_DENIED')),
				);
		}

		$queuesToConnect = $this->getQueuesToConnect();
		if ($queuesToConnect === null)
		{
			return $result
				->addError(
					new Error(Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_QUEUES_TO_CONNECT_NOT_FOUND')),
				);
		}

		$botJoin = $this->getBotJoin();
		if ($botJoin === null)
		{
			return $result
				->addError(
					new Error(Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_BOT_JOIN_INCORRECT')),
				);
		}

		$botTime = $this->getBotTime();
		if ($botTime === null)
		{
			return $result
				->addError(
					new Error(Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_BOT_TIME_INCORRECT')),
				);
		}

		$botLeft = $this->getBotLeft();
		if ($botLeft === null)
		{
			return $result
				->addError(
					new Error(Loc::getMessage('IMOL_BOT_SETTINGS_ACTIVITY_ERROR_BOT_LEFT_INCORRECT')),
				);
		}

		$connectedQueues = [];
		foreach ($queuesToConnect as $queue)
		{
			$configService = new Config($responsibleId);

			$queueConfig = $configService->get($queue->getId());
			if (!$queueConfig)
			{
				$error = new Error(
					Loc::getMessage(
						'IMOL_BOT_SETTINGS_ACTIVITY_ERROR_QUEUE_CONFIG_NOT_FOUND_MSGVER_1',
						[
							'#LINE#' => $this->formatOpenLineName($queue),
						],
					)
				);

				$result->addError($error);

				continue;
			}

			$welcomeBotId = (int)($queueConfig['WELCOME_BOT_ID'] ?? 0);
			$isQueueHasAnotherBot =
				$queueConfig['WELCOME_BOT_ENABLE'] === 'Y'
				&& $welcomeBotId > 0
				&& $welcomeBotId !== $botId
			;
			if ($isQueueHasAnotherBot)
			{
				$queueUrl = Common::getContactCenterPublicFolder() . 'lines_edit/?ID=' . $queue->getId() . '&SIDE=Y&PAGE=bots';
				$error = new Error(
					Loc::getMessage(
						'IMOL_BOT_SETTINGS_ACTIVITY_ERROR_QUEUE_HAS_WELCOME_BOT',
						[
							'#LINE#' => $this->formatOpenLineName($queue),
							'[link]' => "<a href='{$queueUrl}'>",
							'[/link]' => '</a>',
						],
					)
				);

				$result->addError($error);

				continue;
			}

			$configUpdateResult = $configService->update($queue->getId(), [
				'WELCOME_BOT_ENABLE' => 'Y',
				'WELCOME_BOT_ID' => $botId,
				'WELCOME_BOT_JOIN' => $botJoin,
				'WELCOME_BOT_TIME' => $botTime,
				'WELCOME_BOT_LEFT' => $botLeft,
			]);

			if (!$configUpdateResult->isSuccess())
			{
				$error = new Error(
					Loc::getMessage(
						'IMOL_BOT_SETTINGS_ACTIVITY_ERROR_CANNOT_QUEUE_UPDATE_MSGVER_1',
						[
							'#LINE#' => $this->formatOpenLineName($queue),
							'[link]' => '<a href="/crm">',
							'[/link]' => '</a>',
						],
					)
				);

				$result->addError($error);

				continue;
			}

			$connectedQueues[] = Loc::getMessage(
				'IMOL_BOT_SETTINGS_ACTIVITY_OPENLINE_NAME_WITH_QUOTE',
				[
					'#LINE#' => $this->formatOpenLineName($queue),
				],
			);
		}

		$this->{self::RETURN_PARAM_SUCCESS_QUEUE_CONNECTED_LIST} = implode(', ', $connectedQueues);

		return $result;
	}

	private function getResponsibleId(): ?int
	{
		return CBPHelper::extractFirstUser($this->{self::PARAM_RESPONSIBLE_ID}, $this->getDocumentId());
	}

	private function getBotName(): ?string
	{
		$botName = (string)$this->{self::PARAM_BOT_NAME};
		if (empty($botName))
		{
			return null;
		}

		return $botName;
	}

	private function getBotCode(): ?string
	{
		$botCode = (string)$this->{self::PARAM_BOT_CODE};
		if (empty($botCode))
		{
			return null;
		}

		return $botCode;
	}

	private function getQueuesToConnect(): ?Queue
	{
		$queues = $this->{self::PARAM_QUEUES_TO_CONNECT};
		if (!is_array($queues))
		{
			return null;
		}

		$queueIds = [];
		foreach ($queues as $queue)
		{
			if (!is_array($queue))
			{
				continue;
			}

			$queueEntityId = $queue['entityId'] ?? null;
			if ($queueEntityId !== 'imopenlines-queue')
			{
				continue;
			}

			$queueId = (int)($queue['id'] ?? 0);
			if ($queueId <= 0)
			{
				continue;
			}

			$queueIds[] = $queueId;
		}

		if (empty($queueIds))
		{
			return null;
		}

		$queues = Queue::getQueuesByIds($queueIds);
		if ($queues->isEmpty())
		{
			return null;
		}

		return $queues;
	}

	private function getBotJoin(): ?string
	{
		$botJoin = $this->{self::PARAM_BOT_JOIN};
		if (!self::isCorrectBotJoin($botJoin))
		{
			return null;
		}

		return $botJoin;
	}

	private function getBotTime(): ?int
	{
		$botTime = $this->{self::PARAM_BOT_TIME};
		if (!self::isCorrectBotTime($botTime))
		{
			return null;
		}

		return (int)$botTime * 60;
	}

	private function getBotLeft(): ?string
	{
		$botLeft = $this->{self::PARAM_BOT_LEFT};
		if (!self::isCorrectBotLeft($botLeft))
		{
			return null;
		}

		return $botLeft;
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return self::getPropertiesMap([]);
	}

	private function checkModuleIncluded(string $module): bool
	{
		if (!Loader::includeModule($module))
		{
			$errorMessage = Loc::getMessage(
				'IMOL_BOT_SETTINGS_ACTIVITY_ERROR_MODULE_NOT_INCLUDED',
				[
					'#MODULE#' => $module,
				],
			);

			$this->trackError($errorMessage);

			return false;
		}

		return true;
	}

	private function trackErrorsByResult(Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			$this->trackError($error->getMessage());
		}
	}

	private function setReturnErrors(Result $result): void
	{
		$errors = $result->getErrors();
		$errorMessages = array_map(static fn (Error $error) => $error->getMessage(), $errors);

		$this->{self::RETURN_PARAM_ERRORS} = implode(PHP_EOL, $errorMessages);
	}

	private function fail(Result $result): void
	{
		$this->trackErrorsByResult($result);
		$this->setReturnErrors($result);
	}

	private static function isCorrectBotJoin(mixed $botJoin): bool
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return false;
		}

		return in_array($botJoin, [Config::BOT_JOIN_FIRST, Config::BOT_JOIN_ALWAYS], true);
	}

	private static function isCorrectBotTime(mixed $botTime): bool
	{
		if (!is_numeric($botTime))
		{
			return false;
		}

		$botTime = (int)$botTime;

		return in_array($botTime, [0, 1, 3, 5, 10, 15, 30], true);
	}

	private static function isCorrectBotLeft(mixed $botLeft): bool
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return false;
		}

		return in_array($botLeft, [Config::BOT_LEFT_QUEUE, Config::BOT_LEFT_CLOSE], true);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	private function formatOpenLineName(QueueItem $queue): string
	{
		return htmlspecialcharsbx(trim((string)$queue->getName(), ' '));
	}
}
