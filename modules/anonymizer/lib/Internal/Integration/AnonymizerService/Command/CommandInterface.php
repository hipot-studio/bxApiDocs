<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command;

use Bitrix\Anonymizer\Public\Context\QuestContext;
use Bitrix\Anonymizer\Public\Resolvers\ResolverInterface;

/**
 * Command to external anonymizer API. Knows request payload and how to handle raw response.
 * {@see \Bitrix\Anonymizer\Internal\Repository\CommandRegistry} routes lifecycle calls.
 */
interface CommandInterface
{
	public static function getCode(): string;

	/**
	 * Proxy action name for anonymizerproxy (e.g. anonymizerproxy.api.Anonymize.text).
	 */
	public function getProxyAction(): string;

	public function getData(): array;

	/**
	 * Processes raw API response on successful request (e.g. persist replacements). Called from onResponse.
	 *
	 * @param array<string, mixed> $rawResult
	 */
	public function processResponse(int $questId, array $rawResult): void;

	/**
	 * Fills resolver from persisted response data (written earlier in processResponse on onResponse).
	 */
	public function fillResolver(int $questId, ResolverInterface $resolver): void;

	/**
	 * Fills quest context with error state (e.g. context->error) for handler delivery.
	 */
	public function processError(QuestContext $context, string $error): void;
}
