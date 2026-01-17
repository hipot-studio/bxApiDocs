<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent;

class DocumentTypeEventOptions
{
	public function __construct(
		public readonly ?array $moduleIds = null, // null = all modules
		/**
		 * Example:
		 * [
		 *	'crm' => [
		 *		'option1' => 'value1',
		 *		'option2' => 'value2',
		 *	],
		 *	'lists' => [
		 *		'option1' => 'value1',
		 *	],
		 * ]
		 * @var array<string, array<string, mixed>>
		 */
		public readonly array $moduleOptions = [],
	)
	{}
}
