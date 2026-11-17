<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AI;

use Bitrix\Mail\Internal\Service\Message\MessageClassifierInterface;

/**
 * The one place that decides which engine stands behind the role; the name of the vendor stays in this
 * layer.
 */
final class MessageClassifierFactory
{
	public static function getInstance(): MessageClassifierInterface
	{
		return new TritonMessageClassifier(TritonMessageClassifier::CONTEXT_ID);
	}
}

