<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Bizproc\Activity\ReadableExpressionResolver;
use Bitrix\Bizproc\Activity\ReadableExpressionResolverProvider;

/**
 * Resolution context passed to \Bitrix\Bizproc\Public\Activity\Interface\ActivityContentBlockProviderInterface::getContentBlock().
 *
 * Carries the {@see ContentBlockScope} assembled from the whole template so a consumer activity can
 * resolve cross-node references for display (e.g. a dynamic storage title declared by a separate
 * CreateStorageNode) by key, without knowing the producer's node type. Display-only; never persisted.
 *
 * {@see ReadableExpressionResolver} is carried the same way: an activity reaches it only through the
 * context. Both fields are optional - a context without the resolver has no readable form.
 */
final class ContentBlockContext
{
	/**
	 * @param ?ReadableExpressionResolverProvider $readableExpressionResolverProvider Resolvers of the request
	 *     by document type. Held rather than exposed: an activity is given the one resolver of its own
	 *     context, while choosing the document type of a node is the business of the pass that walks them
	 *     ({@see self::forDocumentType()}). A context built without it names every node in the one type it
	 *     was built in, which is what every caller predating the retargeting does.
	 */
	public function __construct(
		public readonly ?ContentBlockScope $scope = null,
		public readonly ?ReadableExpressionResolver $readableExpressionResolver = null,
		private readonly ?ReadableExpressionResolverProvider $readableExpressionResolverProvider = null,
	) {}

	/** The same context carrying another scope: the resolvers of the request are kept as they are. */
	public function withScope(?ContentBlockScope $scope): self
	{
		if ($scope === $this->scope)
		{
			return $this;
		}

		return new self($scope, $this->readableExpressionResolver, $this->readableExpressionResolverProvider);
	}

	/**
	 * The same context with the readable form resolved in the document type of one node: a node declaring a
	 * type of its own works in that type, and a reference to a document field inside it names a field of that
	 * document and not of the one the template is built on.
	 *
	 * A type the user may not read leaves the context with no resolver at all, so such a node keeps the plain
	 * text it showed before the readable form existed instead of being named through a right it lacks.
	 */
	public function forDocumentType(array $documentType): self
	{
		if ($this->readableExpressionResolverProvider === null)
		{
			return $this;
		}

		$resolver = $this->readableExpressionResolverProvider->getResolver($documentType);

		return $resolver === $this->readableExpressionResolver
			? $this
			: new self($this->scope, $resolver, $this->readableExpressionResolverProvider)
		;
	}
}
