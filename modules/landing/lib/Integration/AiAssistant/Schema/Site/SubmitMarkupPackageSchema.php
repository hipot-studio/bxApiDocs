<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormColorNormalizer;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;
use Bitrix\Landing\Markup\Form\FormDeclaration;

class SubmitMarkupPackageSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'mode' => [
				'type' => 'string',
				'enum' => ['create', 'update'],
				'description' => 'Submission mode: create builds a new site from the package, update applies the'
					. ' package as the new version of the custom-markup page of the site the binding is pinned to.'
					. ' Both modes target the site by bindingId only.',
			],
			AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD => [
				'type' => ['string', 'integer', 'null'],
				'description' => 'Numeric landing AI-site binding identifier of the page context (required in both'
					. ' modes): bindingId of the page context, not the trigger entityId. In mode=update the server'
					. ' resolves the single page of the bound site itself; a page identifier is never passed.',
			],
			'uploadId' => [
				'type' => ['string', 'null'],
				'description' => 'Upload buffer identifier returned by put_markup_file. Every file already uploaded into the buffer becomes part of the package automatically; inline files of the same path override the buffered copy.',
			],
			'package' => [
				'type' => 'object',
				'description' => 'Complete markup package: the entry HTML document, asset files, the image declaration and the page meta.',
				'properties' => [
					'entry' => [
						'type' => 'object',
						'description' => 'Entry HTML document of the package: either inline (path + base64 content + encoding=base64) or a reference to an already stored file (path + fileId + hash). With an uploadId a bare path is enough when the entry was uploaded into the buffer.',
						'properties' => [
							'path' => [
								'type' => 'string',
								'description' => 'Entry path, e.g. index.html. Latin letters, digits, "_", "-", ".", "/" only.',
							],
							'content' => [
								'type' => ['string', 'null'],
								'description' => 'Full HTML source of the entry document, base64-encoded (inline form). Content without encoding=base64 is refused with MARKUP_INLINE_FORBIDDEN, undecodable base64 with MARKUP_BASE64_INVALID.',
							],
							'encoding' => [
								'type' => ['string', 'null'],
								'enum' => ['base64', null],
								'description' => 'Encoding of content: always base64 for the inline form.',
							],
							'fileId' => [
								'type' => ['integer', 'null'],
								'description' => 'Stored file identifier (reference form).',
							],
							'hash' => [
								'type' => ['string', 'null'],
								'description' => 'sha256 of the referenced content (reference form).',
							],
						],
						'required' => ['path'],
						'additionalProperties' => false,
					],
					'files' => [
						'type' => 'array',
						'description' => 'Asset files referenced by the entry, paths relative to the entry. Each file is either inline (path + base64 content + encoding=base64) or a reference to an already stored file (path + fileId + hash) - an uploaded or unchanged file is never re-sent by content. Large files go through put_markup_file.',
						'items' => [
							'type' => 'object',
							'properties' => [
								'path' => [
									'type' => 'string',
									'description' => 'Asset path relative to the entry, e.g. styles.css or js/app.js.',
								],
								'content' => [
									'type' => ['string', 'null'],
									'description' => 'Full content of the asset, base64-encoded (inline form).',
								],
								'encoding' => [
									'type' => ['string', 'null'],
									'enum' => ['base64', null],
									'description' => 'Encoding of content: always base64 for the inline form.',
								],
								'fileId' => [
									'type' => ['integer', 'null'],
									'description' => 'Stored file identifier (reference form).',
								],
								'hash' => [
									'type' => ['string', 'null'],
									'description' => 'sha256 of the referenced content (reference form).',
								],
							],
							'required' => ['path'],
							'additionalProperties' => false,
						],
					],
					'images' => [
						'type' => 'array',
						'description' => 'Image declaration for bx-asset:// tokens used in the markup.',
						'items' => [
							'type' => 'object',
							'properties' => [
								'token' => [
									'type' => 'string',
									'description' => 'Token without the bx-asset:// scheme, [a-z0-9][a-z0-9_-]{0,63}.',
								],
								'kind' => [
									'type' => 'string',
									'enum' => ['generate', 'external'],
									'description' => 'generate: the portal generates the image by prompt; external: the portal downloads it by https url.',
								],
								'prompt' => [
									'type' => ['string', 'null'],
									'description' => 'Image generation prompt (kind=generate).',
								],
								'aspect' => [
									'type' => ['string', 'null'],
									'enum' => ['landscape', 'portrait', 'square', null],
									'description' => 'Aspect of the generated image (kind=generate).',
								],
								'url' => [
									'type' => ['string', 'null'],
									'description' => 'https source url of the image (kind=external).',
								],
								'alt' => [
									'type' => ['string', 'null'],
									'description' => 'Text description of the image.',
								],
								'outcome' => [
									'type' => ['string', 'null'],
									'description' => 'Outcome as read from the package'
										. ' (generated, downloaded, failed); pass it back as is,'
										. ' the portal ignores it.',
								],
								'reason' => [
									'type' => ['string', 'null'],
									'description' => 'Reason of a failed outcome as read from the package; '
										. 'pass it back as is, the portal ignores it.',
								],
								'regenerate' => [
									'type' => 'boolean',
									'description' => 'true asks for a new picture even with the same prompt'
										. ' and aspect; the only retry of a failed one (kind=generate).',
								],
							],
							'required' => ['token', 'kind'],
							'additionalProperties' => false,
						],
					],
					'forms' => $this->getFormsProperty(),
					'meta' => [
						'type' => 'object',
						'description' => 'Page meta. Always pass meta as separate fields; extracting it from the entry HTML is a degradation.',
						'properties' => [
							'title' => [
								'type' => ['string', 'null'],
								'description' => 'Full site name: brand and offer.',
							],
							'pageTitle' => [
								'type' => ['string', 'null'],
								'description' => 'Short page name for the page title and the browser tab:'
									. ' the brand or 1-3 words, up to 30 characters.'
									. ' Without it the page takes the part of title before a dash.',
							],
							'description' => ['type' => ['string', 'null']],
							'ogTitle' => ['type' => ['string', 'null']],
							'ogDescription' => ['type' => ['string', 'null']],
							'ogImage' => ['type' => ['string', 'null']],
						],
						'required' => [],
						'additionalProperties' => false,
					],
				],
				'required' => ['entry'],
				'additionalProperties' => false,
			],
		];
	}

	/**
	 * Unsupported values are described, not enumerated: the portal ignores them with a warning
	 * instead of refusing the whole package.
	 */
	private function getFormsProperty(): array
	{
		$colors = [];
		foreach (CrmFormColorNormalizer::COLOR_KEYS as $key)
		{
			$colors[$key] = [
				'type' => 'string',
				'description' => 'Hex color, e.g. #1f6feb.',
			];
		}

		return [
			'type' => 'array',
			'description' => 'CRM form declaration. Each form is placed in the entry HTML by a literal attribute: '
				. 'data-bx-crm-form="<token>" on an empty container embeds the form, '
				. 'data-bx-crm-form-popup="<token>" on a clickable element opens it in a popup. '
				. 'Never write a <form> tag: the portal creates a CRM form for each placed token and renders it. '
				. 'A marker without a declaration gets a default form. '
				. 'At most ' . FormDeclaration::LIMIT_FORMS . ' forms per package.',
			'items' => [
				'type' => 'object',
				'properties' => [
					'token' => [
						'type' => 'string',
						'description' => 'Form token used by the markers, [a-z0-9][a-z0-9_-]{0,63}. '
							. 'Unique within the package.',
					],
					'name' => [
						'type' => ['string', 'null'],
						'description' => 'Form name shown in CRM.',
					],
					'fields' => [
						'type' => 'array',
						'description' => 'Fields of a new form, at most ' . FormDeclaration::LIMIT_FIELDS
							. '. Without fields the default preset is used. '
							. 'Ignored when the token is already bound to a form: '
							. 'fields of an existing form are changed by the form tools.',
						'items' => [
							'type' => 'object',
							'properties' => [
								'kind' => [
									'type' => 'string',
									'description' => 'Field kind: ' . implode(', ', FormDeclaration::KINDS)
										. '. Any other kind is ignored with a warning.',
								],
								'label' => [
									'type' => ['string', 'null'],
									'description' => 'Field caption.',
								],
								'required' => [
									'type' => ['boolean', 'null'],
									'description' => 'Whether the field is mandatory.',
								],
							],
							'required' => ['kind'],
							'additionalProperties' => false,
						],
					],
					'design' => [
						'type' => 'object',
						'description' => 'Form design matching the site. Invalid values are dropped.',
						'properties' => [
							'colors' => [
								'type' => 'object',
								'description' => 'Form colors; the portal adjusts them for readable contrast.',
								'properties' => $colors,
								'additionalProperties' => false,
							],
							'font' => [
								'type' => 'object',
								'properties' => [
									'family' => [
										'type' => 'string',
										'description' => 'Font family name.',
									],
									'uri' => [
										'type' => ['string', 'null'],
										'description' => 'https url of the font stylesheet.',
									],
								],
								'required' => ['family'],
								'additionalProperties' => false,
							],
							'style' => [
								'type' => ['string', 'null'],
								'description' => 'Form style: ' . implode(', ', FormDeclaration::STYLES) . '.',
							],
							'theme' => [
								'type' => ['string', 'null'],
								'description' => 'Form theme: ' . implode(', ', FormDeclaration::THEMES) . '.',
							],
						],
						'additionalProperties' => false,
					],
					'formId' => [
						'type' => ['integer', 'null'],
						'description' => 'Read-only: the bound CRM form returned by get_markup_package; '
							. 'ignored on submission.',
					],
					'formName' => [
						'type' => ['string', 'null'],
						'description' => 'Read-only: the name of the bound CRM form; ignored on submission.',
					],
				],
				'required' => ['token'],
				'additionalProperties' => false,
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['mode', 'package'];
	}
}
