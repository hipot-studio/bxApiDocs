<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Deadline;

use Bitrix\Tasks\Replication\Template\Enum\DeadlineType;
use Bitrix\Tasks\Util\Type;
use Bitrix\Tasks\V2\Internal\Entity\Template;

/**
 * Effective deadline offset of a template, in seconds: null - no deadline, 0 - on the creation
 * day, positive - that many seconds after creation.
 *
 * Within repeat params a positive DEADLINE_OFFSET always wins. Otherwise the creation day is set
 * only by an explicit DEADLINE_TYPE = creation_day: a zero or absent offset alone means no deadline.
 * Without repeat params only a positive DEADLINE_AFTER gives a deadline.
 */
final class DeadlineOffsetResolver
{
	public static function fromTemplate(Template $template): ?int
	{
		if ($template->replicateParams !== null)
		{
			return self::fromParams(
				$template->replicateParams->deadlineOffset,
				$template->replicateParams->deadlineType,
			);
		}

		return self::fromDeadlineAfter($template->deadlineAfter);
	}

	public static function fromRawParams(mixed $replicateParams, ?int $deadlineAfter): ?int
	{
		$params = self::unpack($replicateParams);

		if ($params !== null)
		{
			$offset = isset($params['DEADLINE_OFFSET']) ? (int)$params['DEADLINE_OFFSET'] : null;
			$type = $params['DEADLINE_TYPE'] ?? null;

			return self::fromParams($offset, is_string($type) ? $type : null);
		}

		return self::fromDeadlineAfter($deadlineAfter);
	}

	private static function fromParams(?int $offset, ?string $type): ?int
	{
		if ($offset > 0)
		{
			return $offset;
		}

		return $type === DeadlineType::CreationDay->value ? 0 : null;
	}

	private static function fromDeadlineAfter(?int $deadlineAfter): ?int
	{
		return $deadlineAfter > 0 ? $deadlineAfter : null;
	}

	private static function unpack(mixed $replicateParams): ?array
	{
		if (is_array($replicateParams))
		{
			return $replicateParams;
		}

		if (is_string($replicateParams) && $replicateParams !== '')
		{
			$parsed = Type::unSerializeArray($replicateParams);

			return is_array($parsed) ? $parsed : null;
		}

		return null;
	}
}
