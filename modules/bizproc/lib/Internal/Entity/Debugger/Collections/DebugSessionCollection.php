<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger\Collections;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;

/**
 * @method DebugSession|null getFirstCollectionItem()
 * @method DebugSession|null getLastCollectionItem()
 */
class DebugSessionCollection extends BaseEntityCollection
{
	public function __construct(DebugSession ...$debugSessions)
	{
		foreach ($debugSessions as $debug)
		{
			$this->collectionItems[] = $debug;
		}
	}

	public function getBySessionId(string $sessionId): ?DebugSession
	{
		foreach ($this as $debug)
		{
			/** @var DebugSession $debug */
			if ($debug->getWorkflowId() === $sessionId)
			{
				return $debug;
			}
		}

		return null;
	}

	public function filterByUserId(int $userId): self
	{
		$filtered = [];

		foreach ($this as $debug)
		{
			/** @var DebugSession $debug */
			if ($debug->getUserId() === $userId)
			{
				$filtered[] = $debug;
			}
		}

		return new self(...$filtered);
	}

	public function getActive(): self
	{
		$active = [];

		foreach ($this as $debug)
		{
			/** @var DebugSession $debug */
			if (!$debug->isEnded())
			{
				$active[] = $debug;
			}
		}

		return new self(...$active);
	}

	public function getEnded(): self
	{
		$ended = [];

		foreach ($this as $debug)
		{
			/** @var DebugSession $debug */
			if ($debug->isEnded())
			{
				$ended[] = $debug;
			}
		}

		return new self(...$ended);
	}
}
