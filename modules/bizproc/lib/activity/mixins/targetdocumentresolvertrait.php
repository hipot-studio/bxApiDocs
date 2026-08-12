<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Mixins;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Public\Activity\Interface\TargetDocumentResolver;

trait TargetDocumentResolverTrait
{
	private const TARGET_FILTER_ID_PROPERTY = 'TargetFilterId';

	private ?array $runtimeDocumentId = null;

	public function initializeFromArray($arParams)
	{
		parent::initializeFromArray($arParams);

		if (!is_array($arParams) || !array_key_exists(self::TARGET_FILTER_ID_PROPERTY, $arParams))
		{
			return;
		}

		$this->arProperties[self::TARGET_FILTER_ID_PROPERTY] = $arParams[self::TARGET_FILTER_ID_PROPERTY];
		$this->setPropertiesTypes([
			self::TARGET_FILTER_ID_PROPERTY => [
				'Type' => FieldType::STRING,
			],
		]);
	}

	public function getDocumentId()
	{
		if (is_array($this->runtimeDocumentId))
		{
			return $this->runtimeDocumentId;
		}

		return parent::getDocumentId();
	}

	public function setDocumentId($documentId)
	{
		$this->runtimeDocumentId = null;

		parent::setDocumentId($documentId);
	}

	protected function resolveTargetDocumentId(): array
	{
		$this->runtimeDocumentId = null;

		$resolver = $this->getTargetDocumentResolver();
		$documentId = $resolver
			? $resolver->resolveDocumentId($this)
			: $this->resolveFallbackDocumentId()
		;

		if (is_array($documentId[0] ?? null))
		{
			$documentId = $documentId[0];
		}

		return $this->runtimeDocumentId = $documentId;
	}

	protected function resolveTargetDocumentType(array $documentId): array
	{
		$fallbackDocumentId = $this->resolveFallbackDocumentId();
		$fallbackDocumentType = $this->resolveFallbackDocumentType();
		if ($documentId === $fallbackDocumentId)
		{
			return $fallbackDocumentType;
		}

		$resolver = $this->getTargetDocumentResolver();
		if ($resolver)
		{
			return $resolver->resolveDocumentType(
				$documentId,
				$fallbackDocumentType,
			);
		}

		return $fallbackDocumentType;
	}

	private function getTargetDocumentResolver(): ?TargetDocumentResolver
	{
		return Container::instance()->getTargetDocumentResolverRegistry()->resolve($this);
	}

	private function resolveFallbackDocumentId(): array
	{
		$documentId = $this->getDocumentId();
		if (is_array($documentId))
		{
			return $documentId;
		}

		$rootDocumentId = $this->getRootActivity()->getDocumentId();

		return is_array($rootDocumentId) ? $rootDocumentId : [];
	}

	private function resolveFallbackDocumentType(): array
	{
		$documentId = $this->getDocumentId();
		if (is_array($documentId))
		{
			return $this->getDocumentType();
		}

		$rootDocumentType = $this->getRootActivity()->getDocumentType();

		return is_array($rootDocumentType) ? $rootDocumentType : [];
	}
}
