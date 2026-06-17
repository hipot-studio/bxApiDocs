<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Document;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Internal\Entity\Document\DocumentComplexId;
use Bitrix\Bizproc\Internal\Entity\Document\DocumentComplexType;
use Bitrix\Bizproc\Internal\Service\Document\Dto\ResolvedDocumentCollectionDto;
use Bitrix\Bizproc\Internal\Service\Document\Dto\ResolvedDocumentDto;
use Bitrix\Bizproc\Internal\Service\Document\Result\ResolveDocumentsResult;

class DocumentsResolver
{
	public const ERROR_REASON_DOCUMENT_TYPE_DOES_NOT_MATCH_DOCUMENT_ID = 'DOCUMENT_TYPE_DOES_NOT_MATCH_DOCUMENT_ID';
	private const ERROR_REASON_EMPTY_RESOLVED_DOCUMENTS = 'EMPTY_RESOLVED_DOCUMENTS';

	public function resolveFromPayload(array $payload): ResolveDocumentsResult
	{
		if (isset($payload['signedDocuments']))
		{
			return $this->resolveManySignedDocuments($payload['signedDocuments']);
		}

		if (isset($payload['documents']))
		{
			return $this->resolveMany($payload['documents']);
		}

		if (isset($payload['documentType']) || isset($payload['documentId']))
		{
			return $this->resolvePayloadItem($payload['documentType'] ?? null, $payload['documentId'] ?? null);
		}

		if (isset($payload['signedDocumentType']) || isset($payload['signedDocumentId']))
		{
			return $this->resolvePayloadItem(
				$payload['signedDocumentType'] ?? null,
				$payload['signedDocumentId'] ?? null,
				true,
			);
		}

		return $this->createRequiredParameterResult();
	}

	public function resolveUniqueDocumentTypesFromPayload(array $payload): ResolveDocumentsResult
	{
		$result = $this->resolveFromPayload($payload);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$documents = $result->getDocuments();
		if ($documents === null)
		{
			return $this->createUnknownErrorResult();
		}

		return $this->uniqueDocumentsByType($documents);
	}

	protected function resolvePayloadItem(
		mixed $documentType,
		mixed $documentId = null,
		bool $isSignedPayload = false,
	): ResolveDocumentsResult
	{
		$complexDocumentType = $this->normalizeDocument($documentType, $isSignedPayload);
		if ($complexDocumentType === null)
		{
			return \CBPHelper::isEmptyValue($documentType)
				? $this->createRequiredParameterResult()
				: $this->createInvalidParameterResult('documentType', $documentType)
				;
		}

		if (!$this->isValidEntity($complexDocumentType))
		{
			return $this->createInvalidParameterResult('documentType', $documentType);
		}

		$complexDocumentId = null;
		if (!\CBPHelper::isEmptyValue($documentId))
		{
			$complexDocumentId = $this->normalizeDocument($documentId, $isSignedPayload);
			if ($complexDocumentId === null)
			{
				return $this->createInvalidParameterResult('documentId', $documentId);
			}

			if (!\CBPHelper::isEqualDocumentEntity($complexDocumentType, $complexDocumentId))
			{
				return $this->createDocumentMismatchResult($documentId);
			}
		}

		$result = new ResolveDocumentsResult();
		$result->setDocuments(
			new ResolvedDocumentCollectionDto([
				new ResolvedDocumentDto(
					complexDocumentType: new DocumentComplexType(
						(string)($complexDocumentType[0] ?? ''),
						(string)($complexDocumentType[1] ?? ''),
						(string)($complexDocumentType[2] ?? ''),
					),
					complexDocumentId: $complexDocumentId
						? new DocumentComplexId(
							(string)($complexDocumentId[0] ?? ''),
							(string)($complexDocumentId[1] ?? ''),
							$complexDocumentId[2] ?? '',
						)
						: null,
				),
			]),
		);

		return $result;
	}

	private function resolveMany(mixed $documents): ResolveDocumentsResult
	{
		return $this->resolveManyDocuments($documents, 'documents');
	}

	private function resolveManySignedDocuments(mixed $documents): ResolveDocumentsResult
	{
		return $this->resolveManyDocuments($documents, 'signedDocuments', true);
	}

	private function resolveManyDocuments(
		mixed $documents,
		string $parameterName,
		bool $forceSignedPayload = false,
	): ResolveDocumentsResult
	{
		if (!is_array($documents) || $documents === [])
		{
			return $this->createInvalidParameterResult($parameterName, $documents);
		}

		$resolvedDocuments = [];
		$resolvedDocumentHashes = [];

		foreach ($documents as $document)
		{
			if (!is_array($document))
			{
				return $this->createInvalidParameterResult($parameterName, $document);
			}

			$result = $this->resolveDocumentItem($document, $forceSignedPayload);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$resolvedCollection = $result->getDocuments();
			if (!$resolvedCollection)
			{
				return $this->createUnknownErrorResult();
			}

			foreach ($resolvedCollection->documents as $resolvedDocument)
			{
				$documentKey = serialize([
					$resolvedDocument->complexDocumentType->toArray(),
					$resolvedDocument->complexDocumentId?->toArray(),
				]);

				if (isset($resolvedDocumentHashes[$documentKey]))
				{
					continue;
				}

				$resolvedDocumentHashes[$documentKey] = true;
				$resolvedDocuments[] = $resolvedDocument;
			}
		}

		$result = new ResolveDocumentsResult();
		$result->setDocuments(new ResolvedDocumentCollectionDto($resolvedDocuments));

		return $result;
	}

	private function resolveDocumentItem(array $document, bool $forceSignedPayload = false): ResolveDocumentsResult
	{
		$isSignedPayload = $forceSignedPayload || array_key_exists('signedDocumentType', $document);

		return $this->resolvePayloadItem(
			$document[$isSignedPayload ? 'signedDocumentType' : 'documentType'] ?? null,
			$document[$isSignedPayload ? 'signedDocumentId' : 'documentId'] ?? null,
			$isSignedPayload,
		);
	}

	private function uniqueDocumentsByType(ResolvedDocumentCollectionDto $documents): ResolveDocumentsResult
	{
		$uniqueDocuments = [];

		foreach ($documents->documents as $document)
		{
			$documentType = $document->complexDocumentType;
			$documentTypeKey = $documentType->getKey();
			$existingDocument = $uniqueDocuments[$documentTypeKey] ?? null;
			if ($existingDocument === null)
			{
				$uniqueDocuments[$documentTypeKey] = $document;
			}
		}

		$result = new ResolveDocumentsResult();
		$result->setDocuments(new ResolvedDocumentCollectionDto(array_values($uniqueDocuments)));

		return $result;
	}
	private function normalizeDocument(mixed $value, bool $isSignedPayload): ?array
	{
		return $isSignedPayload
			? $this->normalizeSignedDocument($value)
			: $this->normalizePlainDocument($value)
		;
	}

	private function isValidEntity(array $complexDocumentType): bool
	{
		return DocumentEntityChecker::isValid(
			(string)($complexDocumentType[0] ?? ''),
			(string)($complexDocumentType[1] ?? ''),
		);
	}

	private function normalizePlainDocument(mixed $value): ?array
	{
		if (!is_array($value))
		{
			return null;
		}

		return \CBPHelper::normalizeComplexDocumentId($value);
	}

	private function normalizeSignedDocument(mixed $value): ?array
	{
		if (!is_string($value) || $value === '')
		{
			return null;
		}

		return $this->normalizePlainDocument(\CBPDocument::unSignDocumentType($value));
	}

	private function createRequiredParameterResult(string $parameter = 'documentType'): ResolveDocumentsResult
	{
		return $this->createErrorResult(
			ErrorMessage::PARAM_REQUIRED->getError(
				['#NAME#' => $parameter],
				ErrorMessage::PARAM_REQUIRED->value,
				['parameter' => $parameter],
			),
		);
	}

	private function createInvalidParameterResult(
		string $parameter,
		mixed $value,
	): ResolveDocumentsResult
	{
		return $this->createErrorResult(
			ErrorMessage::INVALID_PARAM_ARG->getError(
				[
					'#PARAM#' => $parameter,
					'#VALUE#' => \CBPHelper::stringify($value),
				],
				ErrorMessage::INVALID_PARAM_ARG->value,
				['parameter' => $parameter],
			),
		);
	}

	private function createDocumentMismatchResult(mixed $documentId): ResolveDocumentsResult
	{
		return $this->createErrorResult(
			ErrorMessage::INVALID_PARAM_ARG->getError(
				[
					'#PARAM#' => 'documentId',
					'#VALUE#' => \CBPHelper::stringify($documentId),
				],
				ErrorMessage::INVALID_PARAM_ARG->value,
				[
					'parameter' => 'documentId',
					'reason' => self::ERROR_REASON_DOCUMENT_TYPE_DOES_NOT_MATCH_DOCUMENT_ID,
				],
			),
		);
	}

	private function createUnknownErrorResult(): ResolveDocumentsResult
	{
		return $this->createErrorResult(
			ErrorMessage::UNKNOWN_ERROR->getError(
				[],
				ErrorMessage::UNKNOWN_ERROR->value,
				['reason' => self::ERROR_REASON_EMPTY_RESOLVED_DOCUMENTS],
			),
		);
	}

	private function createErrorResult(Error $error): ResolveDocumentsResult
	{
		$result = new ResolveDocumentsResult();
		$result->addError($error);

		return $result;
	}
}
