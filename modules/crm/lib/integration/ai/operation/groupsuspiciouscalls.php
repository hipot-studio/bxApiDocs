<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Dispatcher;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\GroupSuspiciousCallsPayload;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Operation\Handler\GroupSuspiciousCallsHandler;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\GenerateCallCriteriaEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main;
use CCrmOwnerType;

final class GroupSuspiciousCalls extends AbstractOperation
{
	public const TYPE_ID = 11;
	public const CONTEXT_ID = 'group_suspicious_calls';

	protected const PAYLOAD_CLASS = GroupSuspiciousCallsPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE;

	private const SYNTHETIC_TARGET_ID = 1;

	/** @var array<int, array{id: int, data: array{theme: ?string, product: ?string, intent: ?string}}> */
	private array $calls = [];
	/** @var array<int, array{id: int, name: string, description: string}> */
	private array $scripts = [];

	public function __construct(?int $userId = null, ?int $parentJobId = null)
	{
		parent::__construct(
			new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, self::SYNTHETIC_TARGET_ID),
			$userId,
			$parentJobId,
		);
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return $userId === Dispatcher::getSystemUserId() && self::isSuitableTarget($target);
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::CopilotCallAssessment;
	}

	public function setCalls(array $calls): self
	{
		$this->calls = $calls;

		return $this;
	}

	public function setScripts(array $scripts): self
	{
		$this->scripts = $scripts;

		return $this;
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): Main\Result
	{
		return new Main\Result();
	}

	protected function getAIPayload(): Main\Result
	{
		$additionalData = [
			'calls' => $this->calls,
			'scripts' => $this->scripts,
		];

		return PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setAdditionalData($additionalData)
			->setMarkers([])
			->getResult()
		;
	}

	// region notify
	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
	): void
	{
		AIManager::logger()->error(
			'{date}: {class}: Group suspicious calls job error on target: {target}' . PHP_EOL,
			['class' => self::class, 'target' => $result->getTarget()],
		);

		Dispatcher::getInstance()->pingAgent();
	}
	// endregion

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);
		if (!isset($json['groups']) || !is_array($json['groups']))
		{
			AIManager::logger()->warning(
				'{date}: {class}: unexpected payload shape, expected {"groups":[...]}; got keys: {keys}',
				['class' => self::class, 'keys' => implode(',', array_keys($json))],
			);

			return new GroupSuspiciousCallsPayload([]);
		}

		$groups = [];
		foreach ($json['groups'] as $group)
		{
			if (!is_array($group))
			{
				continue;
			}

			$items = array_values(array_map('intval', (array)($group['items'] ?? [])));
			if (empty($items))
			{
				continue;
			}

			$groups[] = [
				'scriptId' => isset($group['script_id']) ? (int)$group['script_id'] : null,
				'groupName' => $group['group_name'] ?? null,
				'items' => $items,
			];
		}

		return new GroupSuspiciousCallsPayload(['groups' => $groups]);
	}

	// Required by the contract, but unreachable: this type is in AbstractOperation::EXCLUDED_OPERATION_TYPES.
	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new GenerateCallCriteriaEvent();
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var GroupSuspiciousCallsPayload|null $payload */
		$payload = $result->getPayload();
		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: empty payload or failed result' . PHP_EOL,
				['class' => self::class, 'target' => $result->getTarget()],
			);

			return;
		}

		$jobId = $result->getJobId() ?? 0;
		if ($jobId <= 0)
		{
			return;
		}

		(new GroupSuspiciousCallsHandler($jobId, $payload))->handle();
	}
}
