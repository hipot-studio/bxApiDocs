<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\Classification;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Internal\Service\Message\ClassificationLabel;
use Bitrix\Mail\Internal\Service\Message\ClassificationService;

class SetEmailClassificationTool extends ToolContract
{
	public const ACTION_NAME = 'set_email_classification';

	public function __construct(
		private readonly ClassificationService $classificationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return self::ACTION_NAME;
	}

	public function getDescription(): string
	{
		return
			"Adds or updates a single classification label on an email; "
			. "other labels already set on the email are not affected. "
			. "Allowed labels: " . implode(', ', $this->allowedLabels()) . ". "
			. "Requires the message identifier obtained from the search_emails tool."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'messageId' => [
					'type' => 'integer',
					'description' => 'Identifier of the email to label.',
					'minimum' => 1,
				],
				'label' => [
					'type' => 'string',
					'enum' => $this->allowedLabels(),
					'description' => 'Classification label to set.',
				],
			],
			'required' => ['messageId', 'label'],
			'additionalProperties' => false,
		];
	}

	/* Registered but hidden from the assistant: labelling is not offered to users yet. */
	public function canList(int $userId): bool
	{
		return false;
	}

	public function canRun(int $userId): bool
	{
		return false;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$messageId = (int)($args['messageId'] ?? 0);
		$rawLabel = (string)($args['label'] ?? '');

		if ($messageId <= 0)
		{
			throw new McpException('Parameter messageId is required and must be a positive integer.');
		}

		$label = ClassificationLabel::tryFrom($rawLabel);
		if ($label === null)
		{
			throw new McpException(
				'Parameter label must be one of: ' . implode(', ', $this->allowedLabels()) . '.'
			);
		}

		if (!$this->classificationService->addByUser($userId, $messageId, $label)->isSuccess())
		{
			throw new McpException('Message not found or access denied.');
		}

		return [
			'success' => true,
			'messageId' => $messageId,
			'label' => $label->value,
		];
	}

	/**
	 * @return string[]
	 */
	private function allowedLabels(): array
	{
		return array_map(static fn (ClassificationLabel $case): string => $case->value, ClassificationLabel::cases());
	}
}
