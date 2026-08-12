<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class EventValidator implements EventValidatorInterface
{
	private const ALLOWED_EVENT_NAMES = [
		EventName::SiteChanged,
		EventName::BlockChanged,
	];

	private const ALLOWED_ENTITY_TYPES = [
		EventEntityType::Site,
		EventEntityType::Block,
	];

	private const ALLOWED_ACTIONS = [
		EventAction::Create,
		EventAction::Update,
		EventAction::Delete,
	];

	private const ALLOWED_SOURCES = [
		EventSource::AiTool,
	];

	private const ALLOWED_CONTRACTS = [
		[EventName::SiteChanged, EventEntityType::Site, EventAction::Create],
		[EventName::SiteChanged, EventEntityType::Site, EventAction::Update],
		[EventName::SiteChanged, EventEntityType::Site, EventAction::Delete],
		[EventName::BlockChanged, EventEntityType::Block, EventAction::Update],
	];

	public function validate(Event $event): Result
	{
		$result = new Result();

		$this->validateValue(
			$result,
			$event->getEventName(),
			self::ALLOWED_EVENT_NAMES,
			'Unknown realtime event name.',
			'LANDING_REALTIME_EVENT_NAME_UNKNOWN',
		);
		$this->validateValue(
			$result,
			$event->getEntityType(),
			self::ALLOWED_ENTITY_TYPES,
			'Unknown realtime entity type.',
			'LANDING_REALTIME_ENTITY_TYPE_UNKNOWN',
		);
		$this->validateValue(
			$result,
			$event->getAction(),
			self::ALLOWED_ACTIONS,
			'Unknown realtime event action.',
			'LANDING_REALTIME_EVENT_ACTION_UNKNOWN',
		);
		$this->validateValue(
			$result,
			$event->getSource(),
			self::ALLOWED_SOURCES,
			'Unknown realtime event source.',
			'LANDING_REALTIME_EVENT_SOURCE_UNKNOWN',
		);

		if ($result->isSuccess())
		{
			$this->validateContract($result, $event);
		}

		return $result;
	}

	private function validateValue(
		Result $result,
		string $value,
		array $allowedValues,
		string $message,
		string $code,
	): void
	{
		if (!in_array($value, $allowedValues, true))
		{
			$result->addError(new Error($message, $code));
		}
	}

	private function validateContract(Result $result, Event $event): void
	{
		$contract = [
			$event->getEventName(),
			$event->getEntityType(),
			$event->getAction(),
		];

		if (!in_array($contract, self::ALLOWED_CONTRACTS, true))
		{
			$result->addError(new Error(
				'Unsupported realtime event contract.',
				'LANDING_REALTIME_EVENT_CONTRACT_UNSUPPORTED',
			));
		}
	}
}
