<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Access\LegalRegistry\RegistryAccess;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\UsageRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

class UsageService
{
	public static function getInstance(): self
	{
		$locator = ServiceLocator::getInstance();
		$service = $locator->has(self::class) ? $locator->get(self::class) : null;

		return $service instanceof self ? $service : new self();
	}

	public function execute(int $userId, string $operation, int $formId, ?int $documentId, ?int $versionId,
		bool $checked, bool $required, int $expectedSourceRevision): RegistryResult
	{
		try
		{
			if ($userId <= 0 || !$this->access($userId)->canEditForm())
			{
				return self::error('NOT_FOUND_OR_DENIED');
			}
			if (!in_array($operation, ['bind', 'replace', 'unbind', 'closeSource'], true)
				|| $formId <= 0 || $formId > 2147483647
				|| $expectedSourceRevision <= 0 || $expectedSourceRevision > 2147483647
				|| ($documentId !== null && ($documentId <= 0 || $documentId > 2147483647))
				|| ($versionId !== null && ($versionId <= 0 || $versionId > 2147483647)))
			{
				return self::error('INVALID_PAYLOAD');
			}
			if ($operation === 'closeSource')
			{
				if ($documentId !== null || $versionId !== null) { return self::error('INVALID_PAYLOAD'); }
			}
			elseif ($documentId === null || ($operation === 'unbind' ? $versionId !== null : $versionId === null))
			{
				return self::error('INVALID_PAYLOAD');
			}
			$formNameSnapshot = null;
			if (in_array($operation, ['bind', 'replace'], true))
			{
				$form = $this->capture($formId);
				$formNameSnapshot = $form['name'];
				$ensured = $this->ensure($formId, $form['name']);
				if (!$ensured->isSuccess()) { return $ensured; }
			}

			return $this->change($formId, $operation, $documentId, $versionId,
				$checked, $required, $expectedSourceRevision, $formNameSnapshot);
		}
		catch (\UnexpectedValueException) { return self::error('NOT_FOUND_OR_DENIED'); }
		catch (\InvalidArgumentException) { return self::error('INVALID_PAYLOAD'); }
		catch (\Throwable) { return self::error('STORAGE_ERROR'); }
	}

	protected function access(int $userId): RegistryAccess { return RegistryAccess::forUser($userId); }

	protected function capture(int $formId): array
	{
		return FormSourceGateway::getInstance()->captureLiveForm($formId);
	}

	protected function ensure(int $formId, string $name): RegistryResult
	{
		return (new UsageRepository())->ensureSource($formId, $name);
	}

	protected function change(int $formId, string $operation, ?int $documentId, ?int $versionId,
		bool $checked, bool $required, int $revision, ?string $formNameSnapshot = null): RegistryResult
	{
		return (new UsageRepository())->changeUsage($formId, $operation, $documentId, $versionId,
			$checked, $required, $revision, $formNameSnapshot);
	}

	protected static function error(string $code): RegistryResult
	{
		return (new RegistryResult())->addError(new Error($code, $code));
	}
}
