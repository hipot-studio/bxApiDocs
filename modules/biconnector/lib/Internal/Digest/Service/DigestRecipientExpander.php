<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

/**
 * Turns the recipients of a digest as they were picked (people, departments, groups, document expressions)
 * into people: the digest and its greeting are addressed to one person at a time, and a department left
 * as it was picked would be one step of a template loop.
 *
 * Runs inside a bizproc activity only: the expansion is the one of the bizproc runtime.
 * Left open for extension so tests can see who expands the recipients.
 */
class DigestRecipientExpander
{
	/**
	 * @param mixed $recipients The value of a recipients input as the template resolved it.
	 * @param mixed $documentId Document of the workflow, the scope of document expressions and roles.
	 *
	 * @return string[] One user code per person, without repeats, in the order the recipients were picked.
	 */
	public function expand(mixed $recipients, mixed $documentId): array
	{
		$codes = array_filter(
			\CBPHelper::flatten((array)($recipients ?? [])),
			static fn($code): bool => \CBPHelper::hasStringRepresentation($code) && trim((string)$code) !== '',
		);
		if ($codes === [])
		{
			return [];
		}

		return array_map(
			static fn($userId): string => 'user_' . (int)$userId,
			\CBPHelper::extractUsers(array_values($codes), $documentId),
		);
	}
}
