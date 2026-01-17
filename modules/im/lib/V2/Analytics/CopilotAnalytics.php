<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Analytics\Event\ChatEvent;
use Bitrix\Im\V2\Analytics\Event\CopilotEvent;
use Bitrix\Im\V2\Analytics\Event\CopilotMentionEvent;
use Bitrix\Im\V2\Analytics\Event\Event;
use Bitrix\Im\V2\Message;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Result;

class CopilotAnalytics extends AbstractAnalytics
{
	protected const GENERATE = 'generate';
	protected const RECEIVED_RESULT = 'received_result';
	protected const MENTION = 'copilot_mention';
	protected const RECEIVED_MENTION_RESULT = 'copilot_reply';
	protected const ADD_USER = 'add_user';
	protected const DELETE_USER = 'delete_user';
	protected const CHANGE_ROLE = 'change_role';
	protected const CHANGE_MODEL = 'change_model';
	protected const ANALYTICS_STATUS = [
		'SUCCESS' => 'success',
		'ERROR_PROVIDER' => 'error_provider',
		'ERROR_B24' => 'error_b24',
		'ERROR_LIMIT_DAILY' => 'error_limit_daily',
		'ERROR_LIMIT_MONTHLY' => 'error_limit_monthly',
		'ERROR_AGREEMENT' => 'error_agreement',
		'ERROR_TURNEDOFF' => 'error_turnedoff',
		'ERROR_LIMIT_BAAS' => 'error_limit_baas',
	];

	public function addGenerate(Result $result, bool $byMention, Message $targetMessage): void
	{
		$this->async(function () use ($targetMessage, $result, $byMention) {
			$this->sendCopilotEvent(self::GENERATE, $result, $targetMessage);
			if ($byMention)
			{
				$this->sendMentionEvent(self::MENTION, $result, $targetMessage);
			}
			$hasAnswer = ($result->getData()['MESSAGE'] ?? '') !== '';
			if ($hasAnswer)
			{
				$this->sendReceiveEvent($result, $targetMessage, $byMention);
			}
		});
	}

	public function addReceive(Result $result, Message $targetMessage, bool $byMention): void
	{
		$this->async(function () use ($result, $targetMessage, $byMention) {
			$this->sendReceiveEvent($result, $targetMessage, $byMention);
		});
	}

	protected function sendCopilotEvent(string $name, Result $result, Message $targetMessage): void
	{
		$status = $this->getCopilotStatus($result);
		$promptCode = $targetMessage->getParams()->get(Message\Params::COPILOT_PROMPT_CODE)->getValue();
		$this
			->createCopilotEvent($name, $promptCode)
			?->setType($this->getTypeForEvent($targetMessage))
			?->setStatus($status)
			?->send()
		;
	}

	protected function sendMentionEvent(string $name, Result $result, Message $targetMessage): void
	{
		$status = $this->getCopilotStatus($result);
		$this
			->createCopilotMentionEvent($name, $targetMessage)
			?->setStatus($status)
			?->send()
		;
	}

	protected function sendReceiveEvent(Result $result, Message $targetMessage, bool $byMention): void
	{
		$this->sendCopilotEvent(self::RECEIVED_RESULT, $result, $targetMessage);
		if ($byMention)
		{
			$this->sendMentionEvent(self::RECEIVED_MENTION_RESULT, $result, $targetMessage);
		}
	}

	public function addChangeRole(string $oldRole): void
	{
		$this->async(function () use ($oldRole) {

			(new ChatEvent(self::CHANGE_ROLE, $this->chat, $this->getContext()->getUserId()))
				->setP1(null)
				->setP3('oldRole_' . Event::convertUnderscore($oldRole))
				->send()
			;
		});
	}

	public function addChangeEngine(string $oldEngineName): void
	{
		$this->async(function () use ($oldEngineName) {
			(new ChatEvent(self::CHANGE_MODEL, $this->chat, $this->getContext()->getUserId()))
				->setP1(null)
				->setP3('oldProvider_' . Event::convertUnderscore($oldEngineName))
				->send()
			;
		});
	}

	public function addAddUser(): void
	{
		$this
			->createCopilotEvent(self::ADD_USER)
			?->send()
		;
	}

	public function addDeleteUser(): void
	{
		$this
			->createCopilotEvent(self::DELETE_USER)
			?->send()
		;
	}

	protected function createCopilotEvent(
		string $eventName,
		?string $promptCode = null,
	): ?CopilotEvent
	{
		return (new CopilotEvent($eventName, $this->chat, $this->getContext()->getUserId()))
			->setCopilotP1($promptCode)
		;
	}

	protected function createCopilotMentionEvent(string $eventName, Message $message): ?CopilotMentionEvent
	{
		return (new CopilotMentionEvent($eventName, $message, $this->getContext()->getUserId()));
	}

	protected function getCopilotStatus(Result $result): string
	{
		if ($result->isSuccess())
		{
			return self::ANALYTICS_STATUS['SUCCESS'];
		}

		$error = $result->getErrors()[0];

		if (!isset($error))
		{
			return self::ANALYTICS_STATUS['ERROR_B24'];
		}

		return match ($error->getCode()) {
			CopilotChatBot::AI_ENGINE_ERROR_PROVIDER => self::ANALYTICS_STATUS['ERROR_PROVIDER'],
			CopilotChatBot::LIMIT_IS_EXCEEDED_DAILY => self::ANALYTICS_STATUS['ERROR_LIMIT_DAILY'],
			CopilotChatBot::LIMIT_IS_EXCEEDED_MONTHLY => self::ANALYTICS_STATUS['ERROR_LIMIT_MONTHLY'],
			CopilotChatBot::ERROR_AGREEMENT => self::ANALYTICS_STATUS['ERROR_AGREEMENT'],
			CopilotChatBot::LIMIT_IS_EXCEEDED_BAAS => self::ANALYTICS_STATUS['ERROR_LIMIT_BAAS'],
			default => self::ANALYTICS_STATUS['ERROR_B24'],
		};
	}

	protected function getTypeForEvent(Message $message): string
	{
		return match (true)
		{
			$message->getParams()->get(Message\Params::COPILOT_REASONING)->getValue() => 'think',
			default => 'default',
		};
	}
}
