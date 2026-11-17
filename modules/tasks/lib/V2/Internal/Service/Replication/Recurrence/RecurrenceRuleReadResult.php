<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Tasks\V2\Internal\Entity\Template;

final class RecurrenceRuleReadResult extends Result
{
	public const ERROR_RULE_READ_FAILED = 'TASKS_REGULAR_RULE_READ_FAILED';

	/** @var array<int, Template> */
	private array $rules = [];

	/** @var array<int, true> */
	private array $failedTemplateIds = [];

	public function addRule(int $templateId, Template $rule): static
	{
		$this->rules[$templateId] = $rule;

		return $this;
	}

	public function addFailedTemplate(int $templateId): static
	{
		$this->failedTemplateIds[$templateId] = true;
		$this->addError(
			new Error(
				'Unable to read recurrence rule.',
				self::ERROR_RULE_READ_FAILED,
				['templateId' => $templateId],
			),
		);

		return $this;
	}

	/**
	 * @return array<int, Template>
	 */
	public function getRules(): array
	{
		return $this->rules;
	}

	public function hasFailedTemplate(int $templateId): bool
	{
		return isset($this->failedTemplateIds[$templateId]);
	}
}
