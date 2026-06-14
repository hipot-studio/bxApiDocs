<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Call\Integration\AI\Outcome\OutcomeCollection;
use Bitrix\Call\Integration\AI\Outcome\Overview;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

class CBPCallGetFollowUpActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const MAX_LIMIT = 40;
	private const PARAM_LIMIT = 'Limit';
	private const PARAM_USER = 'User';
	private const PARAM_DATE_START = 'DateStart';
	private const PARAM_DATE_END = 'DateEnd';
	private const RETURN_PARAM_FOLLOWUP = 'FollowUp';
	private const RETURN_PARAM_ERROR_MESSAGE = 'ErrorMessage';
	private const RETURN_PARAM_FOLLOWUP_COUNT = 'FollowUpCount';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PARAM_USER => null,
			self::PARAM_DATE_START => null,
			self::PARAM_DATE_END => null,
			self::PARAM_LIMIT => null,

			self::RETURN_PARAM_FOLLOWUP => null,
			self::RETURN_PARAM_ERROR_MESSAGE => null,
			self::RETURN_PARAM_FOLLOWUP_COUNT => 0,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_FOLLOWUP => ['Type' => FieldType::STRING],
			self::RETURN_PARAM_ERROR_MESSAGE => ['Type' => FieldType::STRING],
			self::RETURN_PARAM_FOLLOWUP_COUNT => ['Type' => FieldType::INT],
		]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_USER => [
				'Name' => Loc::getMessage('CALL_GET_FOLLOWUP_ACTIVITY_DESCRIPTION_PROPERTY_USER_NAME'),
				'FieldName' => self::PARAM_USER,
				'Type' => FieldType::USER,
				'Required' => true,
			],
			self::PARAM_DATE_START => [
				'Name' => Loc::getMessage('CALL_GET_FOLLOWUP_ACTIVITY_DESCRIPTION_PROPERTY_DATE_START_NAME'),
				'FieldName' => self::PARAM_DATE_START,
				'Type' => FieldType::DATETIME,
				'Required' => true,
			],
			self::PARAM_DATE_END => [
				'Name' => Loc::getMessage('CALL_GET_FOLLOWUP_ACTIVITY_DESCRIPTION_PROPERTY_DATE_END_NAME'),
				'FieldName' => self::PARAM_DATE_END,
				'Type' => FieldType::DATETIME,
			],
			self::PARAM_LIMIT => [
				'Name' => Loc::getMessage('CALL_GET_FOLLOWUP_ACTIVITY_DESCRIPTION_PROPERTY_LIMIT_NAME'),
				'FieldName' => self::PARAM_LIMIT,
				'Type' => FieldType::INT,
				'Default' => self::MAX_LIMIT,
			],
		];
	}

	protected function reInitialize(): void
	{
		$this->{self::RETURN_PARAM_FOLLOWUP} = null;
		$this->{self::RETURN_PARAM_ERROR_MESSAGE} = null;
		$this->{self::RETURN_PARAM_FOLLOWUP_COUNT} = 0;

		parent::reInitialize();
	}

	public function execute(): int
	{
		if (!Loader::includeModule('call'))
		{
			$this->logExecutionError($this->getNoModuleErrorMessage('call'));

			return CBPActivityExecutionStatus::Closed;
		}

		if (!Loader::includeModule('im'))
		{
			$this->logExecutionError($this->getNoModuleErrorMessage('im'));

			return CBPActivityExecutionStatus::Closed;
		}

		$timestampStart = CBPHelper::makeTimestamp($this->{self::PARAM_DATE_START});
		if (!$timestampStart)
		{
			$this->logExecutionError(Loc::getMessage('CALL_GET_FOLLOWUP_ACTIVITY_NO_DATE_START'));

			return CBPActivityExecutionStatus::Closed;
		}

		$timestampEnd = CBPHelper::makeTimestamp($this->{self::PARAM_DATE_END});
		if (!$timestampEnd)
		{
			$timestampEnd = time();
		}

		if ($timestampStart > $timestampEnd)
		{
			$this->logExecutionError(Loc::getMessage('CALL_GET_FOLLOWUP_ACTIVITY_DATE_END_BEFORE_DATE_START'));

			return CBPActivityExecutionStatus::Closed;
		}

		$user = CBPHelper::extractFirstUser($this->{self::PARAM_USER}, $this->getDocumentId());
		if (empty($user))
		{
			$this->logExecutionError(Loc::getMessage('CALL_GET_FOLLOWUP_ACTIVITY_NO_USER'));

			return CBPActivityExecutionStatus::Closed;
		}

		$limit = (int)$this->{self::PARAM_LIMIT};
		if ($limit <= 0 || $limit > self::MAX_LIMIT)
		{
			$limit = self::MAX_LIMIT;
		}

		$followups = $this->getFollowUps(
			$user,
			\Bitrix\Main\Type\DateTime::createFromTimestamp($timestampStart),
			\Bitrix\Main\Type\DateTime::createFromTimestamp($timestampEnd),
			$limit,
		);

		try
		{
			$this->{self::RETURN_PARAM_FOLLOWUP} = Json::encode($followups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$this->{self::RETURN_PARAM_FOLLOWUP_COUNT} = count($followups);
		}
		catch (ArgumentException $exception)
		{
			$this->logExecutionError($exception->getMessage());
		}

		return CBPActivityExecutionStatus::Closed;
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return self::getPropertiesMap([]);
	}

	private function logExecutionError(?string $message): void
	{
		if (empty($message))
		{
			return;
		}

		$this->trackError($message);
		$this->{self::RETURN_PARAM_ERROR_MESSAGE} = $message;
	}

	private function getFollowUps(
		int $userId,
		\Bitrix\Main\Type\DateTime $dateStart,
		\Bitrix\Main\Type\DateTime $dateEnd,
		int $limit,
	): array
	{
		$callIds = $this->getCallIds($userId, $dateStart, $dateEnd, $limit);
		if (empty($callIds))
		{
			return [];
		}

		$overviews = [];
		foreach ($callIds as $callId)
		{
			$overview = OutcomeCollection::getOutcomesByCallId($callId, [SenseType::OVERVIEW->value])
				?->getOutcomeByType(SenseType::OVERVIEW->value)
				?->getSenseContent()
			;

			if ($overview instanceof Overview)
			{
				$overviews[] = $this->formatOverview($overview);
			}
		}

		return $overviews;
	}

	/**
	 * @param int $userId
	 * @param \Bitrix\Main\Type\DateTime $dateStart
	 * @param \Bitrix\Main\Type\DateTime $dateEnd
	 * @param int $limit
	 *
	 * @return array<int>
	 */
	private function getCallIds(
		int $userId,
		\Bitrix\Main\Type\DateTime $dateStart,
		\Bitrix\Main\Type\DateTime $dateEnd,
		int $limit,
	): array
	{
		$callTableClass = $this->getCallTableClass();
		if ($callTableClass === null || !method_exists($callTableClass, 'getList'))
		{
			return [];
		}

		/** @var \Bitrix\Main\ORM\Query\Result $result */
		$result = $callTableClass::getList([
			'select' => ['ID'],
			'filter' => [
				'>=START_DATE' => $dateStart,
				'<START_DATE' => $dateEnd,
				'=AI_ANALYZE' => 'Y',
				'=CALL_USER.USER_ID' => $userId,
			],
			'order' => ['ID' => 'DESC'],
			'limit' => $limit,
		]);

		$callIds = [];
		while ($row = $result->fetch())
		{
			$callIds[] = (int)$row['ID'];
		}

		return $callIds;
	}

	private function getCallTableClass(): ?string
	{
		if (class_exists(\Bitrix\Call\Model\CallTable::class))
		{
			return \Bitrix\Call\Model\CallTable::class;
		}

		if (class_exists(\Bitrix\Im\Model\CallTable::class))
		{
			return \Bitrix\Im\Model\CallTable::class;
		}

		return null;
	}

	private function formatOverview(Overview $overview): array
	{
		if ($overview->getVersion() > 3)
		{
			return $overview->toRestFormat();
		}

		$result = [];

		if ($overview?->detailedTakeaways)
		{
			$result['detailedTakeaways'] = $overview->detailedTakeaways;
		}

		if ($overview?->topic)
		{
			$result['topic'] = $overview->topic;
		}

		if ($overview?->meetingType)
		{
			if ($overview->meetingType?->explanation)
			{
				$result['meetingType']['explanation'] = $overview->meetingType->explanation;
			}
		}

		if ($overview?->agenda)
		{
			if ($overview->agenda?->explanation)
			{
				$result['agenda']['explanation'] = $overview->agenda->explanation;
			}
		}

		if ($overview?->agreements)
		{
			foreach ($overview->agreements as $row)
			{
				if ($row?->agreement)
				{
					$result['agreements'][] = $row->agreement;
				}
			}
		}
		if ($overview?->meetings)
		{
			foreach ($overview->meetings as $row)
			{
				if ($row?->meeting)
				{
					$result['meetings'][] = $row->meeting;
				}
			}
		}

		if ($overview?->actionItems)
		{
			foreach ($overview->actionItems as $row)
			{
				if ($row?->actionItem)
				{
					$result['actionItems'][] = $row->actionItem;
				}
			}
		}

		if ($overview->getVersion() <= 2)
		{
			if ($overview?->calendar)
			{
				$result['calendar'] = [
					'overhead' => $overview->calendar->overhead,
				];
			}
		}

		if ($overview->getVersion() <= 1)
		{
			if ($overview?->meetingDetails)
			{
				$result['meetingDetails'] = [
					'type' => $overview->meetingDetails->type,
				];
			}

			if ($overview?->tasks)
			{
				foreach ($overview->tasks as $row)
				{
					if ($row?->task)
					{
						$result['tasks'][] = $row->task;
					}
				}
			}
		}

		return $result;
	}

	public static function validateProperties($testProperties = [], \CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];
		$limit = $testProperties[self::PARAM_LIMIT] ?? 0;
		if (is_numeric($limit) && ($limit <= 0 || $limit > self::MAX_LIMIT))
		{
			$errors[] = [
				'parameter' => 'FieldValue',
				'message' => Loc::getMessage('CALL_GET_FOLLOWUP_ACTIVITY_LIMIT_INCORRECT', [
					'#MAX_LIMIT#' => self::MAX_LIMIT
				]),
			];
		}

		return array_merge($errors, parent::validateProperties($testProperties, $user));
	}

	private function getNoModuleErrorMessage(string $module): ?string
	{
		return Loc::getMessage('CALL_GET_FOLLOWUP_ACTIVITY_NO_MODULE_ERROR', [
			'#MODULE#' => $module,
		]);
	}
}