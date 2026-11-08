<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Rest\Enum\APAuth\PasswordType;
use Bitrix\Rest\Enum\Integration\ElementCodeType;
use Bitrix\Rest\Internal\Entity\Application\AppAttribute;
use Bitrix\Rest\Internal\Entity\Integration\Integration;
use Bitrix\Rest\Internal\Model\AppAttributeTable;
use Bitrix\Rest\Internal\Repository\Mapper\IntegrationMapper;
use Bitrix\Rest\Preset\IntegrationTable;
use Bitrix\Rest\Internal\Model\AppTable;

class IntegrationRepository implements RepositoryInterface
{
	public function __construct(
		private readonly IntegrationMapper $mapper = new IntegrationMapper(),
	)
	{
	}

	public function getById(mixed $id): ?Integration
	{
		$ormModel = IntegrationTable::getById($id)->fetchObject();

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	public function getByApplicationExternalId(string $externalApplicationId): ?Integration
	{
		$ormModel = IntegrationTable::query()
			->setSelect(['*'])
			->registerRuntimeField(
				new Reference(
					'APPLICATION',
					AppTable::class,
					Join::on('this.APP_ID', 'ref.ID')
				,
					Join::TYPE_INNER,
				)
			)
			->where('APPLICATION.CLIENT_ID', $externalApplicationId)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	public function getByIncomingWebhookPasswordId(int $passwordId): ?Integration
	{
		$ormModel = IntegrationTable::query()
			->setSelect(['*'])
			->where('PASSWORD_ID', $passwordId)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	public function hasUserIntegrations(array $excludedAppAttributes = []): bool
	{
		return $this->buildUserIntegrationQuery($excludedAppAttributes)
			->setSelect(['ID'])
			->setLimit(1)
			->fetch() !== false
		;
	}

	private function buildUserIntegrationQuery(array $excludedAppAttributes): Query
	{
		$query = IntegrationTable::query()
			->where('ELEMENT_CODE', '!=', ElementCodeType::IN_WEBHOOK->value)
			->where(Query::filter()
				->logic('or')
				->where('PASSWORD.TYPE', PasswordType::User->value)
				->whereNull('PASSWORD_ID'),
			)
		;

		if ($excludedAppAttributes !== [])
		{
			$query->where(Query::filter()
				->logic('or')
				->where('ELEMENT_CODE', '!=', ElementCodeType::APPLICATION->value)
				->whereNull('APP_ID')
				->whereNotIn('APP_ID', $this->buildApplicationIdsByAttributesQuery($excludedAppAttributes)),
			);
		}

		return $query;
	}

	private function buildApplicationIdsByAttributesQuery(array $attributes): Query
	{
		$codeFilter = Query::filter()->logic('or');
		foreach ($attributes as $code => $value)
		{
			$codeFilter->where(
				Query::filter()
					->where('CODE', $code)
					->where('VALUE', $value),
			);
		}

		return AppAttributeTable::query()
			->setSelect(['APP_ID'])
			->where('TYPE', AppAttribute::TYPE_EXTERNAL)
			->where($codeFilter)
			->setGroup(['APP_ID'])
			->registerRuntimeField(new ExpressionField('MATCHED_CODES', 'COUNT(DISTINCT %s)', 'CODE'))
			->where('MATCHED_CODES', count($attributes))
		;
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(Integration $entity): void
	{
		try
		{
			$result = $this->mapper->convertToOrm($entity)->save();
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if ($result->isSuccess() && !$entity->getId())
		{
			$entity->setId($result->getId());
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to save integration');
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		try
		{
			$result = IntegrationTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete integration');
		}
	}
}
