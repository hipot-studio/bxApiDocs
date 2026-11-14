<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\Relation;

/**
 * The provider of relations of one owner module, asked by the synchronization contour of Disk.
 *
 * Implemented by the module that owns the entities: Disk holds neither their data nor their visibility
 * rules and asks the owner about both.
 */
interface RelationProviderInterface
{
	public function getModuleId(): string;

	/**
	 * Public entity types of the provider: snake_case to a human readable name for the documentation.
	 *
	 * @return array<string, string>
	 */
	public function getEntityTypes(): array;

	/**
	 * Entity types of other providers this provider delegates to; an empty list - it does not delegate.
	 * Together with getEntityTypes() they make the allowed answer types.
	 *
	 * @return list<string>
	 */
	public function getDelegatedEntityTypes(): array;

	/**
	 * Connector classes of attachments the provider answers for (they may live in disk/lib/uf/).
	 * The provider has to answer every candidate of these classes with a confirmation or with its absence.
	 *
	 * @return list<class-string<\Bitrix\Disk\Uf\Connector>>
	 */
	public function getConnectorClasses(): array;

	/**
	 * The provider declares that its visibility check takes the category of the reader into account.
	 * Only the providers that declare it are called for extranet and collaber, in both modes.
	 */
	public function confirmsReaderCategory(): bool;

	/**
	 * The provider declares that on truncated candidates it reads the attachments of the object of its own
	 * classes itself, filters them by visibility and answers with the first relationLimit visible ones.
	 */
	public function completesTruncatedCandidates(): bool;

	/**
	 * One call per page. In the full mode it answers with the visible relations of the requested types and
	 * with the confirmed candidates; in the confirm only mode - with the confirmed candidates alone, while
	 * relations and hasMoreRelations stay empty. The number of queries to the database and to external
	 * services does not depend on the number of objects and candidates.
	 *
	 * @throws ProviderUnavailableException the module cannot answer for now
	 */
	public function describeVisible(RelationRequest $request): RelationResponse;
}
