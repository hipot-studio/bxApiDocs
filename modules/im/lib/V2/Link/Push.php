<?php

namespace Bitrix\Im\V2\Link;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Application;

class Push
{
	use ContextCustomer;

	protected const MODULE_ID = 'im';

	protected array $events;
	private static Push $instance;
	private bool $isJobPlanned = false;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		if (!isset(self::$instance))
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function sendFull(LinkRestConvertible $link, string $eventName, array $endpoint): void
	{
		$this->send((new RestAdapter($link))->toRestFormat(), $eventName, $endpoint);
	}

	public function sendIdOnly(LinkRestConvertible $link, string $eventName, array $endpoint): void
	{
		$this->send($link->toRestFormatIdOnly(), $eventName, $endpoint);
	}

	/**
	 * One event for recipients whose payload differs in a part of itself alone: the shared part goes to
	 * the params of the event, the personal parts to the per-user map the pull module merges over them
	 * on delivery. Meant for records of one and the same thing held by many owners - a buffer of one
	 * payload per owner grows with the size of the chat, a single event does not. A queue server that
	 * carries the map sends one message; an older one unfolds it back into a message per recipient, and
	 * either way the recipient reads the payload it would have got addressed on its own.
	 *
	 * @param array<int, array> $paramsByRecipient
	 */
	public function sendPersonalized(array $params, string $eventName, array $paramsByRecipient): void
	{
		if (empty($paramsByRecipient))
		{
			return;
		}

		$this->send($params, $eventName, ['RECIPIENT' => array_keys($paramsByRecipient)], $paramsByRecipient);
	}

	protected function send(array $params, string $eventName, array $endpoint, array $paramsByRecipient = []): void
	{
		$pull = [
			'module_id' => self::MODULE_ID,
			'command' => $eventName,
			'params' => $params,
			'extra' => \Bitrix\Im\Common::getPullExtra(),
		];
		if (!empty($paramsByRecipient))
		{
			$pull['user_params'] = $paramsByRecipient;
		}
		$event = ['params' => $pull];
		if (isset($endpoint['CHAT_ID']))
		{
			$chat = Chat::getInstance((int)$endpoint['CHAT_ID']);
			if ($chat->getType() === Chat::IM_TYPE_COMMENT)
			{
				// RECIPIENT is dropped here on purpose: the tag serves everyone watching the parent
				// channel, and a comment chat never gets a START_ID border of its own to filter by. The
				// readers the post is already hidden from are taken off the delivery by the border of the
				// root post - the publication is deferred, so the ids the border is held against are kept
				// with the event instead of being looked up again when the job runs.
				$event['comment'] = [
					'parentChatId' => (int)$chat->getParentChatId(),
					'postId' => $chat->getParentMessageId(),
				];
			}
			else
			{
				$event['recipient'] = $endpoint['RECIPIENT'] ?? $chat->getRelations()->getUserIds();
			}
		}
		elseif (isset($endpoint['RECIPIENT']))
		{
			$event['recipient'] = $endpoint['RECIPIENT'];
		}
		else
		{
			return;
		}

		$this->events[] = $event;

		$this->deferRun();
	}

	protected function push(): void
	{
		if (!\Bitrix\Main\Loader::includeModule('pull'))
		{
			return;
		}
		foreach ($this->events as $event)
		{
			if (isset($event['recipient']))
			{
				\Bitrix\Pull\Event::add($event['recipient'], $event['params']);
			}
			if (isset($event['comment']))
			{
				Locator::getPullSender()->sendToPostCommentSubscribers(
					$event['comment']['parentChatId'],
					$event['comment']['postId'],
					$event['params']
				);
			}
		}
		$this->isJobPlanned = false;
		$this->events = [];
	}

	protected function deferRun(): void
	{
		if (!$this->isJobPlanned)
		{
			Application::getInstance()->addBackgroundJob(function () {
				$this->push();
			});
			$this->isJobPlanned = true;
		}
	}
}
