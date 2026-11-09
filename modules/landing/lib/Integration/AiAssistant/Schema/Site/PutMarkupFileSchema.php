<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class PutMarkupFileSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'uploadId' => [
				'type' => ['string', 'null'],
				'description' => 'Upload buffer identifier returned by the first put_markup_file call. Pass it to add the next file; omit it together with mode and the target to open a new buffer. Pass it alone, without path and a source, to list what the buffer already holds.',
			],
			'mode' => [
				'type' => ['string', 'null'],
				'enum' => ['create', 'update', null],
				'description' => 'Target mode of a new buffer: create (a new site by chat binding) or update'
					. ' (a new version of the custom-markup page of the site the binding is pinned to).'
					. ' Required without uploadId, ignored with it.',
			],
			AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD => [
				'type' => ['string', 'integer', 'null'],
				'description' => 'Landing AI-site binding identifier of the page context, the target of a new buffer'
					. ' in both modes. Required without uploadId, ignored with uploadId. In mode=update the server'
					. ' resolves the single page of the bound site itself; a page identifier is never passed.',
			],
			'path' => [
				'type' => 'string',
				'description' => 'File path inside the package, relative to the entry, e.g. index.html, styles.css or images/hero.jpg. Latin letters, digits, "_", "-", ".", "/" only; a leading "./" and repeated "/" are dropped, paths differing only in letter case are refused. A repeated path replaces the file whatever the source.',
			],
			'content' => [
				'type' => ['string', 'null'],
				'description' => 'File content. Always base64-encode it and set encoding=base64 for every file, text and binary alike: raw markup can be rewritten by portal security filters in transit. Whole file without offset; with offset it is one part of the file, at most chunkBytes base64 characters.',
			],
			'encoding' => [
				'type' => ['string', 'null'],
				'enum' => ['text', 'base64', null],
				'description' => 'Content encoding. Always pass base64; the text mode is a legacy default that does not survive portal security filters on markup content. A part is always base64.',
			],
			'offset' => [
				'type' => ['integer', 'null'],
				'description' => 'Byte offset of the part in the file: 0 for the first part, then the received value of the previous answer. Turns content into a part of a file too big for one call.',
			],
			'final' => [
				'type' => ['boolean', 'null'],
				'description' => 'true on the last part: the parts are glued and checked against sha256. A repeated final call answers the same file.',
			],
			'sha256' => [
				'type' => ['string', 'null'],
				'description' => 'Lowercase hex sha256 of the whole file (raw bytes). Required with final=true.',
			],
			'diskFileId' => [
				'type' => ['integer', 'null'],
				'description' => 'Bitrix24 Disk file id to take the file from, instead of content: for big files and files the user attached. The file must be readable for the user.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return [];
	}
}
