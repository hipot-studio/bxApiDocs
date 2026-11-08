<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection;

use Bitrix\BIConnector\Superset\Error\UserFacingError;

/**
 * Refusal of one branch of {@see RefusalMap}, ready to be shown to the user.
 *
 * The branch travels twice on purpose: as the error code, which the analytics of the controller
 * reads, and as a field of its own, which the popup reads instead of parsing the text.
 */
final class McpConnectionRefusalError extends UserFacingError
{
	private bool $retryable = false;
	private bool $previousConnectionMayBeLost = false;

	public static function refusal(string $message, string $branch): self
	{
		return new self($message, $branch);
	}

	/**
	 * The contour puts out the alive connection before it makes sure the new one works, so a failed
	 * reissue can leave the user with a dead command: the popup offers another attempt and never
	 * claims the previous connection survived.
	 */
	public static function refusalAfterFailedReissue(string $message, string $branch): self
	{
		$error = new self($message, $branch);
		$error->retryable = true;
		$error->previousConnectionMayBeLost = true;

		return $error;
	}

	public function jsonSerialize(): array
	{
		return array_merge(parent::jsonSerialize(), [
			'branch' => (string)$this->getCode(),
			'retryable' => $this->retryable,
			'previousConnectionMayBeLost' => $this->previousConnectionMayBeLost,
		]);
	}
}
