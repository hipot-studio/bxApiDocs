<?php

namespace Bitrix\Bizproc\Internal\Event;

use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\BlockCollection;
use Bitrix\Main\Event;
use Bitrix\Main\Type\Contract\Arrayable;

class SetupTemplateCurrentDataEvent extends Event implements Arrayable
{
	public const MODULE_ID = 'bizproc';
	public const EVENT_NAME = 'setupTemplateCurrentData';
	public const PARAMETER_TEMPLATE_ID = 'templateId';
	public const PARAMETER_USER_ID = 'userId';
	public const PARAMETER_BLOCKS = 'blocks';
	public const PARAMETER_TEMPLATE_NAME = 'templateName';
	public const PARAMETER_TEMPLATE_DESCRIPTION = 'templateDescription';
	public const PARAMETER_INSTANCE_ID = 'instanceId';

	public function __construct(
		string $moduleId = self::MODULE_ID,
		string $type = self::EVENT_NAME,
		array $parameters = [],
		$filter = null
	)
	{
		parent::__construct($moduleId, $type, $parameters, $filter);
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

	public function setBlocks(?BlockCollection $blockCollection): self
	{
		$this->setParameter(self::PARAMETER_BLOCKS, $blockCollection);

		return $this;
	}

	public function getBlocks(): ?BlockCollection
	{
		return $this->getParameter(self::PARAMETER_BLOCKS);
	}

	public function setTemplateName(?string $templateName): self
	{
		$this->setParameter(self::PARAMETER_TEMPLATE_NAME, $templateName);

		return $this;
	}

	public function getTemplateName(): ?string
	{
		return $this->getParameter(self::PARAMETER_TEMPLATE_NAME);
	}

	public function setTemplateDescription(?string $templateDescription): self
	{
		$this->setParameter(self::PARAMETER_TEMPLATE_DESCRIPTION, $templateDescription);

		return $this;
	}

	public function getTemplateDescription(): ?string
	{
		return $this->getParameter(self::PARAMETER_TEMPLATE_DESCRIPTION);
	}

	public function getInstanceId(): ?string
	{
		return $this->getParameter(self::PARAMETER_INSTANCE_ID);
	}

	public function setInstanceId(?string $instanceId): self
	{
		$this->setParameter(self::PARAMETER_INSTANCE_ID, $instanceId);

		return $this;
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->getUserId(),
			'blocks' => $this->getBlocks()?->toArray(),
			'templateName' => $this->getTemplateName(),
			'templateDescription' => $this->getTemplateDescription(),
			'templateId' => $this->getTemplateId(),
			'instanceId' => $this->getInstanceId(),
		];
	}
}