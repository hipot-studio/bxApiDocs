<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity;

use Bitrix\Bizproc\Activity\Dto\ExpressionLabelDictionary;
use Bitrix\Main\Application;

/**
 * Resolvers of one request, one per document type: the base one, built in the type the caller has already
 * authorized, and one per foreign type a node of the same schema works in - a task node placed in a workflow
 * template names the fields of a task and not the fields of the template.
 *
 * The declarations of the schema stay the business of the template all the same, so every resolver shares the
 * one label dictionary of the request: only the source of the document field names differs between them.
 *
 * A foreign type is admitted to a user who may author workflows on it and to no one else - the very check the
 * settings panel makes before it builds anything in a type a node publishes. The check itself belongs to the
 * caller: this class is handed the verdict and never the rules by which it is reached.
 */
final class ReadableExpressionResolverProvider
{
	private const BASE_KEY = '';

	/** @var array<string, ?ReadableExpressionResolver> document type key => resolver of that type */
	private array $resolvers = [];

	/**
	 * @param array $documentType Document type the caller has authorized; the base resolver names its fields.
	 * @param ExpressionLabelDictionary $labels Declarations of the schema, shared by every resolver of this
	 *     provider: they belong to the template and not to the document type a node happens to work in.
	 * @param \Closure(array): bool $isDocumentTypeAuthorized Whether the current user may read the names of
	 *     the fields of a foreign document type. Asked at most once per type per request.
	 */
	public function __construct(
		private readonly array $documentType,
		private readonly ExpressionLabelDictionary $labels,
		private readonly \Closure $isDocumentTypeAuthorized,
	) {}

	/**
	 * Resolver naming the fields of that document type, or null when the user may not read them. An empty
	 * type, and one equal to the authorized one, are answered by the base resolver.
	 */
	public function getResolver(array $documentType = []): ?ReadableExpressionResolver
	{
		if ($documentType === [] || self::isSameDocumentType($documentType, $this->documentType))
		{
			return $this->resolvers[self::BASE_KEY]
				??= new ReadableExpressionResolver($this->documentType, $this->labels)
			;
		}

		$key = implode('|', $documentType);
		if (!array_key_exists($key, $this->resolvers))
		{
			$this->resolvers[$key] = $this->createResolver($documentType);
		}

		return $this->resolvers[$key];
	}

	/**
	 * Fail-closed: a refused type, and a gate that could not answer at all, leave the node with no readable
	 * form instead of naming the fields of a document the user has no right to. Log for diagnostics.
	 */
	private function createResolver(array $documentType): ?ReadableExpressionResolver
	{
		try
		{
			$isAuthorized = ($this->isDocumentTypeAuthorized)($documentType);
		}
		catch (\Throwable $e)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}

		return $isAuthorized ? new ReadableExpressionResolver($documentType, $this->labels) : null;
	}

	/** The very normalisation the panel paths compare a submitted document type with a stored one by. */
	private static function isSameDocumentType(array $documentType, array $otherDocumentType): bool
	{
		return array_map(strval(...), array_values($documentType))
			=== array_map(strval(...), array_values($otherDocumentType))
		;
	}
}
