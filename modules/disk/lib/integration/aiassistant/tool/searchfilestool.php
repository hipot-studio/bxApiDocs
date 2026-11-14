<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class SearchFilesTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'search_files';
	}

	public function getDescription(): string
	{
		return 'Searches Bitrix24 Disk files visible to the current user. Supports name, date, owner, department, type, duplicate, size, unused and public-link scenarios. Pass periodDays or fromDate/toDate, but not both. For unused files, modification time is used because Disk API does not expose last opened time.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'searchType' => [
					'type' => 'string',
					'enum' => ['by_name', 'duplicates', 'top_by_size', 'unused', 'with_public_links', 'recent', 'uploaded_recently', 'by_owner', 'by_department', 'by_type'],
					'description' => 'Search mode.',
				],
				'query' => [
					'type' => 'string',
					'description' => 'Name fragment for by_name search.',
					'minLength' => 1,
				],
				'storageId' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Optional user, group or common Disk storage scope. Required for duplicates, top_by_size and with_public_links unless folderId is provided.',
				],
				'storageIds' => [
					'type' => 'array',
					'items' => ['type' => 'integer', 'minimum' => 1],
					'minItems' => 1,
					'maxItems' => 50,
					'uniqueItems' => true,
					'description' => 'Optional finite set of storage scopes. Unavailable storages are reported as partial limitations.',
				],
				'folderId' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Optional folder subtree scope inside a user, group or common Disk storage. Required for duplicates, top_by_size and with_public_links unless storageId is provided.',
				],
				'periodDays' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 3650,
					'description' => 'Relative period in days. For unused, selects files not modified for this many days. Cannot be combined with fromDate or toDate.',
				],
				'fromDate' => ['type' => 'string', 'description' => 'Inclusive lower date/time bound. Cannot be combined with periodDays.'],
				'toDate' => ['type' => 'string', 'description' => 'Inclusive upper date/time bound. Cannot be combined with periodDays.'],
				'ownerId' => ['type' => 'integer', 'minimum' => 1],
				'ownerName' => ['type' => 'string', 'minLength' => 1, 'description' => 'Owner name to resolve when ownerId is unknown. Ambiguity is returned without choosing a candidate.'],
				'departmentId' => ['type' => 'integer', 'minimum' => 1],
				'departmentName' => ['type' => 'string', 'minLength' => 1, 'description' => 'Department name to resolve when departmentId is unknown.'],
				'fileType' => [
					'type' => 'string',
					'enum' => ['any', 'image', 'video', 'audio', 'text', 'pdf', 'document'],
					'description' => 'Known categories use Disk metadata. Bounded global uploaded_recently supports only '
						. 'video, audio, pdf or document with an owner. Image, text MIME, extension and by_owner '
						. 'searches require a finite storage or folder scope.',
				],
				'extension' => ['type' => 'string', 'pattern' => '^\\.?[a-zA-Z0-9]{1,16}$', 'description' => 'Wildcard extension search requires a finite storage or folder scope.'],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
				],
				'offset' => [
					'type' => 'integer',
					'minimum' => 0,
					'maximum' => 500,
					'default' => 0,
				],
			],
			'required' => ['searchType'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->searchFiles(
			$userId,
			(string)$args['searchType'],
			[
				'query' => $this->stringArg($args, 'query'),
				'storageId' => $this->intArg($args, 'storageId'),
				'storageIds' => array_values((array)($args['storageIds'] ?? [])),
				'folderId' => $this->intArg($args, 'folderId'),
				'periodDays' => $this->intArg($args, 'periodDays'),
				'dateFrom' => $this->stringArg($args, 'fromDate'),
				'dateTo' => $this->stringArg($args, 'toDate'),
				'ownerId' => $this->intArg($args, 'ownerId'),
				'ownerName' => $this->stringArg($args, 'ownerName'),
				'departmentId' => $this->intArg($args, 'departmentId'),
				'departmentName' => $this->stringArg($args, 'departmentName'),
				'fileType' => $this->stringArg($args, 'fileType'),
				'extension' => $this->stringArg($args, 'extension'),
				'limit' => $this->limitArg($args),
				'offset' => $this->offsetArg($args),
			],
		);
	}
}
