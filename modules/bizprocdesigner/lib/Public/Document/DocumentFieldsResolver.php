<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Document;

use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\BizprocDesigner\Public\Document\Interface\DocumentFieldsContributor;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Loader;

/**
 * Document fields of the new designer: the base set of the document service plus the contribution of the
 * module of the document {@see DocumentFieldsContributor}.
 */
final class DocumentFieldsResolver
{
	private const CONTRIBUTOR_CONFIG_KEY = 'bizprocdesigner.documentfields';

	private array $contributedFields = [];

	/**
	 * @param array $documentType Complex document type, e.g. ['crm', 'CCrmDocumentDeal', 'DEAL'].
	 *
	 * @return array Field name => field description in the format of
	 *               {@see \CBPDocumentService::getDocumentFields()}. A contributed field of a name the
	 *               document service already answers is dropped in favour of the base one.
	 */
	public function getFields(array $documentType): array
	{
		return $this->getBaseFields($documentType) + $this->getContributedFields($documentType);
	}

	/**
	 * The contribution alone, for a caller that has the base set already. Entries of a wrong shape are
	 * dropped, duplicates of the base set are not - that is up to {@see self::getFields()}.
	 */
	public function getContributedFields(array $documentType): array
	{
		$cacheKey = implode('@', $documentType);

		return $this->contributedFields[$cacheKey] ??= $this->collectContributedFields($documentType);
	}

	private function getBaseFields(array $documentType): array
	{
		try
		{
			return \CBPRuntime::getRuntime()->getDocumentService()->getDocumentFields($documentType) ?: [];
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	private function collectContributedFields(array $documentType): array
	{
		$moduleId = (string)($documentType[0] ?? '');

		try
		{
			$contributed = $this->resolveContributor($moduleId)?->getDocumentFields($documentType) ?? [];
		}
		catch (\Throwable $exception)
		{
			Container::getDefaultLogger()->error(
				'Document fields contributor of the module {moduleId} is refused: {message}',
				['moduleId' => $moduleId, 'message' => $exception->getMessage()],
			);

			return [];
		}

		return array_filter(
			$contributed,
			static fn($field, $name) => is_string($name) && is_array($field),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	private function resolveContributor(string $moduleId): ?DocumentFieldsContributor
	{
		if ($moduleId === '' || !Loader::includeModule($moduleId))
		{
			return null;
		}

		$declaration = Configuration::getInstance($moduleId)->get(self::CONTRIBUTOR_CONFIG_KEY);
		$className = is_array($declaration) ? ($declaration['className'] ?? null) : null;
		if (!is_string($className) || !class_exists($className))
		{
			return null;
		}

		$contributor = new $className();

		return $contributor instanceof DocumentFieldsContributor ? $contributor : null;
	}
}
