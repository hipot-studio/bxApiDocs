<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Command;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class UserStatusEventHelper
{
	public const EVENT_BEFORE_FIRE = 'onBeforeUserFire';
	public const EVENT_AFTER_FIRE = 'onAfterUserFire';
	public const EVENT_BEFORE_RESTORE = 'onBeforeUserRestore';

	/**
	 * @return Error[]
	 */
	public static function collectErrors(string $eventName, User $user): array
	{
		$event = new Event('intranet', $eventName, [
			'event' => null,
			'user' => $user,
		]);
		$event->setParameter('event', $event);
		$event->send();
		$errors = [];

		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() !== EventResult::ERROR)
			{
				continue;
			}

			$errors = array_merge($errors, self::normalizeErrors($eventResult->getParameters()));
		}

		return $errors;
	}

	/**
	 * @param array|null $parameters
	 * @return Error[]
	 */
	private static function normalizeErrors(?array $parameters): array
	{
		if (!is_array($parameters))
		{
			return [];
		}

		$errors = $parameters['errors'] ?? $parameters['error'] ?? $parameters['message'] ?? null;
		if ($errors instanceof Error)
		{
			return [$errors];
		}

		if ($errors instanceof ErrorCollection)
		{
			$result = [];
			foreach ($errors as $error)
			{
				$result[] = $error;
			}

			return $result;
		}

		if (is_array($errors))
		{
			$result = [];
			foreach ($errors as $error)
			{
				if ($error instanceof Error)
				{
					$result[] = $error;
				}
				elseif (is_string($error) && $error !== '')
				{
					$result[] = new Error($error);
				}
			}

			return $result;
		}

		if (is_string($errors) && $errors !== '')
		{
			return [new Error($errors)];
		}

		return [];
	}
}
