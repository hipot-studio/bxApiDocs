<?php

namespace Bitrix\Tasks\Control;

use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Control\Exception\TemplateUpdateException;
use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\AddTemplateService;
use Bitrix\Tasks\V2\Internal\Service\DeleteTemplateService;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTemplateService;
use Bitrix\Tasks\V2\Public\Command\Template\AddTemplateCommand;
use Bitrix\Tasks\V2\Public\Command\Template\DeleteTemplateCommand;
use Bitrix\Tasks\V2\Public\Command\Template\UpdateTemplateCommand;
use Exception;

/**
 * @deprecated
 *
 * Public API:
 * @use AddTemplateCommand
 * @use UpdateTemplateCommand
 * @use DeleteTemplateCommand
 *
 * Internal API:
 * @use AddTemplateService
 * @use UpdateTemplateService
 * @use DeleteTemplateService
 */
class Template
{
	private $userId;

	private $deleteSubTemplates = false;
	private $unsafeDelete = false;
	private $skipAgent = false;

	public function __construct(int $userId)
	{
		$this->userId = $userId;
	}

	/**
	 * @return $this
	 */
	public function withDeleteSubTemplates(): self
	{
		$this->deleteSubTemplates = true;

		return $this;
	}

	/**
	 * @return $this
	 */
	public function withUnsafeDelete(): self
	{
		$this->unsafeDelete = true;

		return $this;
	}

	/**
	 * @return $this
	 */
	public function withSkipAgent(): self
	{
		$this->skipAgent = true;

		return $this;
	}

	/**
	 * @param array $fields
	 *
	 * @return TemplateObject
	 * @throws TemplateAddException
	 * @throws \Bitrix\Main\NotImplementedException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function add(array $fields): TemplateObject
	{
		$config = new AddConfig(
			userId: $this->userId,
		);

		$mapper = Container::getInstance()->getOrmTemplateMapper();
		$service = Container::getInstance()->getAddTemplateService();

		$entity = $mapper->mapToEntity($fields);

		$entity = $service->add(
			template: $entity,
			config: $config,
			useConsistency: false
		);

		return $mapper->mapToObject($entity);
	}

	public function delete(int $id): bool
	{
		$config = new DeleteConfig(
			userId: $this->userId,
			unsafeDelete: $this->unsafeDelete,
			deleteSubTemplates: $this->deleteSubTemplates,
		);

		$service = Container::getInstance()->getDeleteTemplateService();

		try
		{
			$service->delete(
				templateId: $id,
				config: $config,
				useConsistency: false
			);
		}
		catch (Exception $e)
		{
			Container::getInstance()->getLogger()->logError($e);

			return false;
		}

		return true;
	}

	/**
	 * @param int $id
	 * @param array $fields
	 *
	 * @return TemplateObject
	 * @throws TemplateUpdateException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function update(int $id, array $fields): TemplateObject
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			skipAgent: $this->skipAgent,
		);

		$mapper = Container::getInstance()->getOrmTemplateMapper();
		$service = Container::getInstance()->getUpdateTemplateService();

		$fields['ID'] = $id;
		$entity = $mapper->mapToEntity($fields);

		$entity = $service->update(
			template: $entity,
			config: $config,
			useConsistency: false
		);

		return $mapper->mapToObject($entity);
	}
}
