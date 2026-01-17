<?php

namespace Bitrix\Bizproc\Internal\Event;

use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Event;

class SetupTemplateValidationEvent extends Event
{
	public const MODULE_ID = 'bizproc';
	public const EVENT_NAME = 'setupTemplateValidation';
	public const PARAMETER_ERRORS = 'errors';
	public const PARAMETER_TEMPLATE_ID = 'templateId';
	public const PARAMETER_USER_ID = 'userId';

	public function __construct(
		string $moduleId = self::MODULE_ID,
		string $type = self::EVENT_NAME,
		array $parameters = [],
		$filter = null
	)
	{
		parent::__construct($moduleId, $type, $parameters, $filter);
	}

	public function getErrors(): ?ErrorCollection
	{
		return $this->getParameter(self::PARAMETER_ERRORS);
	}

	public function getTemplateId(): ?int
	{
		return $this->getParameter(self::PARAMETER_TEMPLATE_ID);
	}

	public function getUserId(): ?int
	{
		return $this->getParameter(self::PARAMETER_USER_ID);
	}

	public function setTemplateId(int $templateId): self
	{
		$this->setParameter(self::PARAMETER_TEMPLATE_ID, $templateId);

		return $this;
	}

	public function setUserId(int $userId): self
	{
		$this->setParameter(self::PARAMETER_USER_ID, $userId);

		return $this;
	}

	public function setErrors(?ErrorCollection $errors = null): self
	{
		$this->setParameter(self::PARAMETER_ERRORS, $errors);

		return $this;
	}
}