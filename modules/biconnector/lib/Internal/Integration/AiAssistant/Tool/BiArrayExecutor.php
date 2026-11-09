<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\SubjectAwareToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\AiAssistant\Request\Dto\RequestSubjectDto;

final class BiArrayExecutor extends SubjectAwareToolContract
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly BaseBiTool|BaseSubjectAwareBiTool $target,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return $this->target->getName();
	}

	public function getDescription(): string
	{
		return $this->target->getDescription();
	}

	public function getSchema(): array
	{
		return $this->target->getSchema();
	}

	public function getInputSchema(): array
	{
		return $this->target->getInputSchema();
	}

	protected function getAnnotations(): ?object
	{
		return $this->target->getFullDescription()->annotations;
	}

	public function canList(int $userId, ?RequestSubjectDto $subject): bool
	{
		return $this->target instanceof BaseSubjectAwareBiTool
			? $this->target->canList($userId, $subject)
			: $this->target->canList($userId);
	}

	public function canRun(int $userId, ?RequestSubjectDto $subject): bool
	{
		return $this->target instanceof BaseSubjectAwareBiTool
			? $this->target->canRun($userId, $subject)
			: $this->target->canRun($userId);
	}

	public function run(int $userId, ?RequestSubjectDto $subject, array $args = []): array
	{
		return $this->target instanceof BaseSubjectAwareBiTool
			? $this->target->run($userId, $subject, $args)
			: $this->target->runWithArguments($userId, $args);
	}
}
