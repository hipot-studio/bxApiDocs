<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot;

use Bitrix\AI;
use Bitrix\AI\Engine\IEngine;
use Bitrix\Landing\Copilot\Generation\Error;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\EventResult;
use Bitrix\Main\Event;
use Bitrix\Main\Web\Json;
use Bitrix\Main;

/**
 * Handle external events
 */
class EventHandler
{
	public static function onQueueJobExecute(Event $event): EventResult
	{
		/**
		 * @var AI\Result $result
		 */
		$result = $event->getParameter('result');
		if (!$result instanceof AI\Result)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$engine = $event->getParameter('engine');
		if (!$engine instanceof IEngine)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$hash = $event->getParameter('queue');
		$request = Request::getByHash($hash);
		if (!$request)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$error = null;

		$jsonData = $result->getPrettifiedData();
		if (!$jsonData)
		{
			$error = Error::createError(Errors::requestEmpty);
		}
		else
		{
			try
			{
				$data = Json::decode(Converter\Json::expandJsonString($jsonData));
				if (!$data || isset($data['error']))
				{
					$error = Error::createError(Errors::requestError);
					$error->message .= $data['error'] ? ': ' . $data['error'] : '';
				}
			}
			catch (ArgumentException)
			{
				$error = Error::createError(Errors::requestInvalid);
			}
		}

		if (!isset($data) && !isset($error))
		{
			return new EventResult(EventResult::SUCCESS);
		}

		if (isset($error))
		{
			$request->saveError($error);
		}
		else
		{
			$request->saveResult($data);
		}

		$generation = new Generation();
		$generationId = $request->getGenerationId();
		if ($generation->initById($generationId))
		{
			$generation->execute();
		}

		return new EventResult(EventResult::SUCCESS);
	}

	public static function onQueueJobFail(Event $event): EventResult
	{
		$hash = $event->getParameter('queue');
		$request = Request::getByHash($hash);
		if (!$request)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$error = Error::createError(Errors::requestFail);
		/**
		 * @var $errorParam Main\Error
		 */
		$errorParam = $event->getParameter('error');
		if ($errorParam)
		{
			$error->message .= ': ' . $errorParam->getCode() . ': ' . $errorParam->getMessage();
		}

		if ($request->getResult() === null)
		{
			$request->saveError($error);
		}

		$generation = new Generation();
		$generationId = $request->getGenerationId();
		if ($generation->initById($generationId))
		{
			$generation->execute();
		}

		return new EventResult(EventResult::SUCCESS);
	}

	/**
	 * A Marta AiNode answer (the markup repair node): the request uuid plays the
	 * role of the queue hash. Answers of foreign node consumers find no request
	 * here and pass through.
	 */
	public static function onAiNodeQueueSuccess(Event $event): EventResult
	{
		$data = $event->getParameter('data');
		$request = self::getNodeRequest($data);
		if (!$request)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$output = $data['output'] ?? null;
		if (is_string($output))
		{
			try
			{
				$output = Json::decode(Converter\Json::expandJsonString($output));
			}
			catch (ArgumentException)
			{
				$output = null;
			}
		}

		if (is_array($output))
		{
			$request->saveResult(['output' => $output]);
		}
		else
		{
			$request->saveError(Error::createError(Errors::requestInvalid));
		}

		self::resumeGeneration($request);

		return new EventResult(EventResult::SUCCESS);
	}

	public static function onAiNodeQueueError(Event $event): EventResult
	{
		$data = $event->getParameter('data');
		$request = self::getNodeRequest($data);
		if (!$request)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		if ($request->getResult() === null)
		{
			$error = Error::createError(Errors::requestFail);
			$message = is_array($data) ? (string)($data['errorMessage'] ?? '') : '';
			if ($message !== '')
			{
				$error->message .= ': ' . $message;
			}
			$request->saveError($error);
		}

		self::resumeGeneration($request);

		return new EventResult(EventResult::SUCCESS);
	}

	private static function getNodeRequest(mixed $data): ?Request
	{
		$uuid = is_array($data) ? (string)($data['uuid'] ?? '') : '';

		return $uuid !== '' ? Request::getByHash($uuid) : null;
	}

	private static function resumeGeneration(Request $request): void
	{
		$generation = new Generation();
		if ($generation->initById($request->getGenerationId()))
		{
			$generation->execute();
		}
	}
}