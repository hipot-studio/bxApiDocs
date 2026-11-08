<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\ScreeningRepeatSaleItemPayload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\ScreeningRepeatSaleItemEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\DataCollector\CopilotMarkerLimitManager;
use Bitrix\Crm\RepeatSale\Schedule\Scheduler;
use Bitrix\Crm\RepeatSale\Service\Entity\RepeatSaleAiScreeningTable;
use Bitrix\Crm\RepeatSale\Service\Handler\AiScreeningOpinion;
use Bitrix\Main;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;

final class ScreeningRepeatSaleItem extends AbstractOperation
{
	public const TYPE_ID = 7;
	public const CONTEXT_ID = 'screening_repeat_sale_item';

	protected const PAYLOAD_CLASS = ScreeningRepeatSaleItemPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_REPEAT_SALE_ENGINE_CODE;
	private const LAUNCH_LOCK_NAME_PREFIX = 'crm_ai_screening_repeat_sale_item_';
	private const LAUNCH_LOCK_TIMEOUT = 1;

	/**
	 * The prompt pins the value in three places: the step names true and false, the answer examples
	 * give them as strings, and the output schema declares the field a bool - so a json boolean is
	 * read above and only these forms reach the lists, with the numeric rendering of a bool as the
	 * one drift the schema line invites. Vocabulary the prompt never uses for the field is left out
	 * on purpose: an unlisted form is read as a stop and reported to the log, and that report is the
	 * only thing that makes a drifted contract visible.
	 */
	private const STOP_FACTOR_TRUE_FORMS = ['true', '1'];
	private const STOP_FACTOR_FALSE_FORMS = ['false', '0', ''];

	private const LOGGED_STOP_FACTOR_VALUE_LIMIT = 200;

	private ?int $segmentId = null;
	private bool $isFreshCycle = false;
	private bool $isManualLaunchForDuplicateResult = true;

	/* @var ItemIdentifier[] $clientIdentifiers */
	private array $clientIdentifiers = [];
	private readonly ?int $parentJobIdForDuplicateResult;

	public function __construct(
		ItemIdentifier $target,
		?int $userId = null,
		?int $parentJobId = null,
	)
	{
		parent::__construct($target, $userId, $parentJobId);

		$this->parentJobIdForDuplicateResult = $parentJobId;
	}

	public function setSegmentId(int $segmentId): self
	{
		$this->segmentId = $segmentId;

		return $this;
	}

	public function setClientIdentifiers(array $clientIdentifiers): self
	{
		$this->clientIdentifiers = $clientIdentifiers;

		return $this;
	}

	public function setIsManualLaunch(bool $isManualLaunch): self
	{
		parent::setIsManualLaunch($isManualLaunch);
		$this->isManualLaunchForDuplicateResult = $isManualLaunch;

		return $this;
	}

	public function launch(): Result
	{
		$this->isFreshCycle = false;

		$connection = Main\Application::getConnection();
		$lockName = $this->getLaunchLockName();

		if (!$connection->lock($lockName, self::LAUNCH_LOCK_TIMEOUT))
		{
			return $this->makeRetryableLockContentionResult();
		}

		try
		{
			return parent::launch();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	protected function checkPreviousJobsBeforeLaunch(): Main\Result
	{
		$pool = Main\Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$result = parent::checkPreviousJobsBeforeLaunch();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		$previousJob = $result->getData()['previousJob'] ?? null;

		if (
			$previousJob instanceof EO_Queue
			&& $previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR
			&& $previousJob->requireRetryCount() >= Result::MAX_RETRY_COUNT
		)
		{
			$this->isFreshCycle = true;

			$freshCycleResult = new Main\Result();
			$freshCycleResult->setData(['previousJob' => $previousJob]);

			return $freshCycleResult;
		}

		return $result;
	}

	protected function getJobUpdateFields(): array
	{
		$fields = parent::getJobUpdateFields();
		if ($this->isFreshCycle)
		{
			$fields['RETRY_COUNT'] = 0;
			$fields['CREATED_TIME'] = new Main\Type\DateTime();
			$fields['FINISHED_TIME'] = null;
		}

		return $fields;
	}

	private function getLaunchLockName(): string
	{
		return self::LAUNCH_LOCK_NAME_PREFIX
			. self::TYPE_ID
			. '_'
			. $this->target->getEntityTypeId()
			. '_'
			. $this->target->getEntityId();
	}

	private function makeRetryableLockContentionResult(): Result
	{
		$result = new Result(
			self::TYPE_ID,
			$this->target,
			$this->userId,
			parentJobId: $this->parentJobIdForDuplicateResult,
			isManualLaunch: $this->isManualLaunchForDuplicateResult,
		);
		$result->addError(
			new Main\Error(
				'Screening launch is already in progress',
				ErrorCode::OPERATION_IS_PENDING,
			),
		);

		return $result;
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::Deal;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::Deal;
	}

	protected function getAIPayload(): Main\Result
	{
		$result = PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setEncodedMarkers(['segment_data', 'crm_data'])
			->setAdditionalData([
				'segmentId' => $this->segmentId,
				'clientIdentifiers' => $this->clientIdentifiers,
			])
			->setMarkers([])
			->getResult()
		;

		/** @var IPayload $payload */
		$payload = $result->getData()['payload'];
		if (!$this->isPayloadMarkersValid($payload->getMarkers()))
		{
			$error = ErrorCode::getInvalidPayloadMarkersForFillRepeatSaleTipsError();

			return (new Main\Result())->addError($error);
		}

		return $result;
	}

	private function isPayloadMarkersValid(array $markers): bool
	{
		if (empty($markers))
		{
			return false;
		}

		$crmData = Json::decode($markers['crm_data'] ?? '');
		if (empty($crmData))
		{
			return false;
		}

		$baseDealInfo = $crmData['base_deal_info'] ?? [];
		if (empty($baseDealInfo))
		{
			return false;
		}

		$limit = CopilotMarkerLimitManager::getInstance()->getMinSufficientAiCollectorDealFieldsLength();
		$dealFields = $baseDealInfo['deal_fields'] ?? [];
		$communicationData = $baseDealInfo['communication_data'] ?? [];

		return
			TextHelper::countCharactersInArrayFlexible($dealFields, true) > $limit
			|| TextHelper::countCharactersInArrayFlexible($communicationData) > $limit;
	}

	/**
	 * The target of this operation is a deal, while the base implementation reads the target id as an
	 * activity id: it would sync a badge and post a timeline notification on a foreign activity that
	 * happens to share the id. Neither surface applies here.
	 */
	protected static function notifyAboutLimitExceededError(Result $result): void
	{
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		// operation is not used in the timeline
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
		// operation is not used in the timeline
	}

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);
		if (empty($json))
		{
			return new ScreeningRepeatSaleItemPayload([]);
		}

		return new ScreeningRepeatSaleItemPayload([
			'category' => $json['category'] ?? null,
			'isRepeatSalePossible' => $json['isRepeatSalePossible'] ?? 0,
			'stopFactor' => self::normalizeStopFactor($json['stopFactor'] ?? null, $job),
		]);
	}

	/**
	 * Fail-closed on purpose: the stop factor is the only protection of a client who asked not to be
	 * contacted, so a value in an unknown form is read as a stop. The neighbouring flags may safely
	 * fall back to false - a wrong type there only switches the occasion of the repeat deal.
	 * A missing key and null mean no stop factor: the model said nothing, which is not the same as
	 * saying something unreadable. A form that plainly means "nothing found" - an empty list of
	 * reasons, an empty value - is readable and must not be read as a stop either: the answer has
	 * one and the same form for every job, so reading a whole class of answers as a stop would halt
	 * the creation of repeat deals on the portal instead of protecting a single client.
	 */
	private static function normalizeStopFactor(mixed $value, EO_Queue $job): bool
	{
		if ($value === null)
		{
			return false;
		}

		if (is_bool($value))
		{
			return $value;
		}

		// an empty list of reasons, an empty object included: no reasons means no stop factor
		if ($value === [])
		{
			return false;
		}

		// a filled list is readable as well: the model enumerated the reasons it had found
		if (is_array($value) && array_is_list($value))
		{
			return true;
		}

		if (is_scalar($value))
		{
			$form = mb_strtolower(trim((string)$value));

			if (in_array($form, self::STOP_FACTOR_FALSE_FORMS, true))
			{
				return false;
			}

			if (in_array($form, self::STOP_FACTOR_TRUE_FORMS, true))
			{
				return true;
			}
		}

		AIManager::logger()->warning(
			'{date}: {class}: Unknown stop factor form in the model answer, read as a stop:'
			. ' {value} (job {jobId}, {entityTypeId}:{entityId})' . PHP_EOL,
			[
				'class' => self::class,
				'value' => self::describeStopFactorValue($value),
				'jobId' => $job->getId(),
				'entityTypeId' => $job->getEntityTypeId(),
				'entityId' => $job->getEntityId(),
			],
		);

		return true;
	}

	/**
	 * The answer has one form for every job, so the warning above repeats for each screened deal.
	 * A type with a truncated value is enough to tell the form apart, while the value itself would
	 * put the whole model answer on that key into the log, once per deal and without a size limit.
	 */
	private static function describeStopFactorValue(mixed $value): string
	{
		try
		{
			$encoded = Json::encode($value);
		}
		catch (Main\ArgumentException)
		{
			return get_debug_type($value);
		}

		return get_debug_type($value) . ' ' . mb_substr($encoded, 0, self::LOGGED_STOP_FACTOR_VALUE_LIMIT);
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var ScreeningRepeatSaleItemPayload $payload */
		$payload = $result->getPayload();

		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error in screening entity item of repeat sale: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$ownerId = $result->getTarget()?->getEntityId();
		$ownerTypeId = $result->getTarget()?->getEntityTypeId();
		$segmentId = $context?->getParameters()['additionalInfo']['segmentId'] ?? null;

		$screeningItem = RepeatSaleAiScreeningTable::query()
			->setSelect(['ID'])
			->setFilter([
				'=OWNER_TYPE_ID' => $ownerTypeId,
				'=OWNER_ID' => $ownerId,
				'=SEGMENT_ID' => $segmentId,
			])
			->setOrder(['CREATED_AT' => 'DESC'])
			->setLimit(1)
			->fetchObject()
		;

		if (!$screeningItem)
		{
			AIManager::logger()->error(
				'{date}: {class}: Screening item not found: {target} {segmentId}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
					'segmentId' => $segmentId,
				],
			);

			return;
		}

		// the stop factor outranks the holiday occasion: a client who asked not to be contacted must
		// not reach a manager even when the purchase was made for a holiday
		if ($payload->stopFactor === true)
		{
			$screeningItem->setAiOpinion(AiScreeningOpinion::isRepeatSaleStopped->value);
		}
		elseif ($payload->isRepeatSalePossible === true)
		{
			$screeningItem->setAiOpinion(AiScreeningOpinion::isRepeatSalePossible->value);
		}
		else
		{
			$screeningItem->setAiOpinion(AiScreeningOpinion::isRepeatSaleNotPossible->value);
		}

		$screeningItem->setCategory($payload->category);
		$screeningItem->save();

		// the children job is scheduled right after the verdict is stored: a row whose payload was
		// rejected never gets an opinion, so waiting for an empty screening queue would block
		// repeat deal creation for the whole day. Duplicates are cut off by the queue dedupe.
		if ($segmentId !== null)
		{
			Scheduler::getInstance()->addChildrenJobsToQueueIfNotExists($segmentId);
		}
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
	): void
	{
		AIManager::logger()->error(
			'{date}: {class}: Error on: {target}' . PHP_EOL,
			[
				'class' => self::class,
				'target' => $result->getTarget(),
			],
		);
	}

	/**
	 * The verdict replaces the generic success status, the holiday category goes as an extra param.
	 */
	protected static function enrichJobFinishEventBuilder(AIBaseEvent $builder, Result $result): void
	{
		$payload = $result->getPayload();
		if (!$payload instanceof ScreeningRepeatSaleItemPayload)
		{
			return;
		}

		// the check order must match onAfterSuccessfulJobFinish: otherwise the analytics status
		// would disagree with the verdict stored in the screening row
		$builder->setStatus(match (true) {
			$payload->stopFactor === true => Dictionary::STATUS_REPEAT_SALE_STOPPED,
			$payload->isRepeatSalePossible === true => Dictionary::STATUS_REPEAT_SALE_POSSIBLE,
			default => Dictionary::STATUS_REPEAT_SALE_NOT_POSSIBLE,
		});

		$normalizedCategory = self::normalizeAnalyticsCategory($payload->category);
		if ($normalizedCategory !== null)
		{
			$builder->setP3('category', $normalizedCategory);
		}
	}

	private static function normalizeAnalyticsCategory(?string $category): ?string
	{
		if ($category === null || trim($category) === '')
		{
			return null;
		}

		return str_replace(['_', ' '], '-', mb_strtolower(trim($category)));
	}

	protected static function getJobFinishEventBuilder(): ScreeningRepeatSaleItemEvent
	{
		return new ScreeningRepeatSaleItemEvent();
	}

	protected function getContextAdditionalInfo(): array
	{
		$additionalInfo = parent::getContextAdditionalInfo();

		$additionalInfo['segmentId'] = $this->segmentId;

		return $additionalInfo;
	}
}
