<?php

namespace Bitrix\DocumentGenerator\Infrastructure\Agent\Access;

use Bitrix\DocumentGenerator\Infrastructure\Agent\BaseAgent;
use Bitrix\DocumentGenerator\Infrastructure\Agent\ExecuteResult;
use Bitrix\DocumentGenerator\Integration\HumanResources;
use Bitrix\DocumentGenerator\Model\TemplateUserTable;
use Bitrix\DocumentGenerator\Repository\RoleAccessRepository;
use Bitrix\DocumentGenerator\Repository\TemplateUserRepository;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Config\Option;

final class DepartmentAccessCodesMigrateAgent extends BaseAgent
{
	private const MODULE_ID = 'documentgenerator';
	private const MIGRATION_DONE_OPTION_NAME = 'is_department_role_access_codes_migrated';

	public function __construct(
		private readonly RoleAccessRepository $roleAccessRepository,
		private readonly HumanResources $humanResources,
		private readonly TemplateUserRepository $templateUserRepository,
	)
	{
	}

	protected static function getInstance(): self
	{
		return new self(
			new RoleAccessRepository(),
			HumanResources::getInstance(),
			new TemplateUserRepository(),
		);
	}

	public function execute(): ExecuteResult
	{
		if (!$this->humanResources->getStorageService()->isCompanyStructureConverted(false))
		{
			$this->setDelayBeforeNextExecution(86400);

			return ExecuteResult::Continue;
		}

		$this->migrateRoleAccessTable();

		$this->migrateTemplateUserTableDepartmentCodes();
		$this->migrateTemplateUserTableSocnetGroups();

		self::markDone();

		return ExecuteResult::Done;
	}

	public static function isDone(): bool
	{
		return Option::get(self::MODULE_ID, self::MIGRATION_DONE_OPTION_NAME, true)
			&& HumanResources::getInstance()->getStorageService()->isCompanyStructureConverted(false)
		;
	}

	private static function markDone(): void
	{
		Option::delete(self::MODULE_ID, [
			'name' => self::MIGRATION_DONE_OPTION_NAME,
		]);
	}

	private function migrateRoleAccessTable(): void
	{
		$roleAccessCollection = $this->roleAccessRepository->findWhereAccessCodeLike('DR%');
		if ($roleAccessCollection->isEmpty())
		{
			return;
		}

		foreach ($roleAccessCollection->getAll() as $roleAccess)
		{
			$convertedAccessCode = $this->convertAccessCode($roleAccess->getAccessCode());
			if ($convertedAccessCode === null)
			{
				continue;
			}

			$roleAccess->setAccessCode($convertedAccessCode);
		}

		$roleAccessCollection->save();
	}

	private function migrateTemplateUserTableDepartmentCodes(): void
	{
		$templateUserCollection = $this->templateUserRepository->findWhereAccessCodeLike('DR%');
		if ($templateUserCollection->isEmpty())
		{
			return;
		}

		$newTemplateUserCollection = TemplateUserTable::createCollection();
		$toDelete = [];

		foreach ($templateUserCollection->getAll() as $templateUser)
		{
			$convertedAccessCode = $this->convertAccessCode($templateUser->getAccessCode());
			if ($convertedAccessCode === null)
			{
				continue;
			}

			$newTemplateUserCollection->add(
				TemplateUserTable::createObject()
					->setTemplateId($templateUser->getTemplateId())
					->setAccessCode($convertedAccessCode),
			);

			$toDelete[] = $templateUser->getAccessCode();
		}

		if ($newTemplateUserCollection->isEmpty())
		{
			return;
		}

		$saveResult = $newTemplateUserCollection->save(ignoreEvents: true);
		if ($saveResult->isSuccess())
		{
			TemplateUserTable::deleteByAccessCodes($toDelete);
		}
	}

	public function migrateTemplateUserTableSocnetGroups(): void
	{
		$templateUsers = $this->templateUserRepository->findWhereAccessCodeLike('SG%');
		if ($templateUsers->isEmpty())
		{
			return;
		}

		$toRemove = [];
		$newTemplateUsers = TemplateUserTable::createCollection();

		foreach ($templateUsers->getAll() as $templateUser)
		{
			$accessCode = new AccessCode($templateUser->getAccessCode());
			if (
				$accessCode->getEntityType() === AccessCode::TYPE_SOCNETGROUP
				&& !str_ends_with($templateUser->getAccessCode(), '_K')
			)
			{
				$newTemplateUsers->add(
					TemplateUserTable::createObject()
						->setTemplateId($templateUser->getTemplateId())
						->setAccessCode($templateUser->getAccessCode() . '_K'),
				);

				$toRemove[] = $templateUser->getAccessCode();
			}
		}

		if ($newTemplateUsers->isEmpty())
		{
			return;
		}

		$saveResult = $newTemplateUsers->save(ignoreEvents: true);
		if ($saveResult->isSuccess())
		{
			TemplateUserTable::deleteByAccessCodes($toRemove);
		}
	}

	private function convertAccessCode(string $accessCode): ?string
	{
		$department = $this->humanResources->getNodeService()->getNodeByAccessCode($accessCode);
		if ($department === null)
		{
			return null;
		}

		return $this->humanResources
			->getAccessCodeService()
			->buildAccessCode('SNDR', $department->id);
	}
}
