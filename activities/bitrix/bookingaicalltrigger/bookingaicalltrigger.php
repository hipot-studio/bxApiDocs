<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Booking\Internals\Container;
use Bitrix\Main\Loader;

class CBPBookingAiCallTrigger extends BaseTrigger
{
	private const PROP_SCENARIO = 'Scenario';

	private const INCOMING_PHONE_NUMBER = 'PhoneNumber';
	private const INCOMING_PROMPT = 'Prompt';
	private const INCOMING_BOOKING_MESSAGE_ID = 'BookingMessageId';
	private const RETURN_PHONE_NUMBER = 'PhoneNumber';
	private const RETURN_PROMPT = 'Prompt';
	private const RETURN_BOOKING_MESSAGE_ID = 'BookingMessageId';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PROP_SCENARIO => null,
			self::RETURN_PHONE_NUMBER => null,
			self::RETURN_PROMPT => null,
			self::RETURN_BOOKING_MESSAGE_ID => null,
		];

		$this->setPropertiesTypes([
			self::PROP_SCENARIO => ['Type' => FieldType::SELECT],
			self::RETURN_PHONE_NUMBER => ['Type' => FieldType::STRING],
			self::RETURN_PROMPT => ['Type' => FieldType::STRING],
			self::RETURN_BOOKING_MESSAGE_ID => ['Type' => FieldType::INT],
		]);
	}

	public function execute(): int
	{
		$context = $this->getEventData();

		$this->{self::RETURN_PHONE_NUMBER} = (string)($context[self::INCOMING_PHONE_NUMBER] ?? '');
		$this->{self::RETURN_PROMPT} = (string)($context[self::INCOMING_PROMPT] ?? '');
		$this->{self::RETURN_BOOKING_MESSAGE_ID} = (int)($context[self::INCOMING_BOOKING_MESSAGE_ID] ?? 0);

		return CBPActivityExecutionStatus::Closed;
	}

	public function createApplyRules(): array
	{
		$rules = parent::createApplyRules();
		$rules[self::PROP_SCENARIO] = $this->{self::PROP_SCENARIO};

		return $rules;
	}

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		$expectedScenario = $rules[self::PROP_SCENARIO] ?? null;
		$actualScenario = $parameters->get(self::PROP_SCENARIO);

		if ($expectedScenario && $actualScenario && $expectedScenario !== $actualScenario)
		{
			return Result::createError(
				new Error('type mismatch')
			);
		}

		if ($expectedScenario && $this->hasDuplicateTemplates())
		{
			return Result::createError(
				new Error('multiple templates with same trigger type')
			);
		}

		return Result::createOk();
	}

	private function hasDuplicateTemplates(): bool
	{
		if (!Loader::includeModule('booking'))
		{
			return false;
		}

		$templateIds = Container::getAiAgentTemplateQuery()->findUserTemplateIds();

		return count($templateIds) > 1;
	}
}
