<?php

namespace Bitrix\Rest\V3\Mcp\Tool;

use Bitrix\AiAssistant\Definition\Dto\ToolDescriptionDto;
use Bitrix\AiAssistant\Definition\Tool\Contract\SubjectAwareToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\AiAssistant\Request\Dto\RequestSubjectDto;
use Bitrix\Rest\V3\Mcp\Access\DefaultMethodAccessChecker;
use Bitrix\Rest\V3\Mcp\Access\MethodAccessCheckerInterface;
use Bitrix\Rest\V3\Mcp\Execution\RestMethodInvoker;
use Bitrix\Rest\V3\Mcp\Schema\ToolInputSchema;
use Bitrix\Rest\V3\Schema\MethodDescription;

final class RestMethodTool extends SubjectAwareToolContract
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly ToolDescriptionDto $toolDescription,
		private readonly MethodDescription $methodDescription,
		private readonly RestMethodInvoker $invoker,
		private readonly ?MethodAccessCheckerInterface $accessChecker = null,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return $this->toolDescription->name;
	}

	public function getDescription(): string
	{
		return $this->toolDescription->description;
	}

	public function getFullDescription(): ToolDescriptionDto
	{
		return $this->toolDescription;
	}

	public function getInputSchema(): array
	{
		if ($this->toolDescription->inputSchema instanceof ToolInputSchema)
		{
			return $this->toolDescription->inputSchema->toArray();
		}

		return (array)$this->toolDescription->inputSchema;
	}

	public function canList(int $userId, ?RequestSubjectDto $subject): bool
	{
		return $this->getAccessChecker()->canAccess($this->methodDescription, $userId);
	}

	public function canRun(int $userId, ?RequestSubjectDto $subject): bool
	{
		return $this->getAccessChecker()->canAccess($this->methodDescription, $userId);
	}

	protected function getAnnotations(): ?object
	{
		return $this->toolDescription->annotations;
	}

	protected function executeStructured(int $userId, ?RequestSubjectDto $subject, array $args): array
	{
		return $this->invoker->invoke($this->methodDescription, $args, $userId);
	}

	private function getAccessChecker(): MethodAccessCheckerInterface
	{
		return $this->accessChecker ?? new DefaultMethodAccessChecker();
	}
}
