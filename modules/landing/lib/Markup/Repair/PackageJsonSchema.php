<?php

namespace Bitrix\Landing\Markup\Repair;

use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormColorNormalizer;
use Bitrix\Landing\Markup\FileStore;
use Bitrix\Landing\Markup\Form\FormDeclaration;
use Bitrix\Landing\Markup\Package;
use Bitrix\Landing\Markup\Validator;

/**
 * The response schema of the repair node and the server-side rebuild of its answer.
 *
 * [external contract] API-07: the answer is the full package of the same structure;
 * an unchanged file comes as {path, hash} without content and is restored byte-for-byte
 * from the submitted package - the node never produces binary bytes and never references
 * portal storage directly. Structured output is not guaranteed by third-party engines,
 * so buildPackage() is the mandatory structural gate: an answer it rejects counts as
 * a failed repair iteration.
 */
class PackageJsonSchema
{
	public function getSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'entry' => [
					'type' => 'object',
					'description' => 'The single HTML entry document of the package, full content.',
					'properties' => [
						'path' => ['type' => 'string'],
						'content' => ['type' => 'string'],
					],
					'required' => ['path', 'content'],
					'additionalProperties' => false,
				],
				'files' => [
					'type' => 'array',
					'description' => 'Every remaining asset of the package. A changed text file carries "content"; '
						. 'an unchanged file carries "hash" copied from the input and no "content"; '
						. 'a deleted file is omitted.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'path' => ['type' => 'string'],
							'content' => ['type' => 'string'],
							'hash' => ['type' => 'string'],
						],
						'required' => ['path'],
						'additionalProperties' => false,
					],
				],
				'images' => [
					'type' => 'array',
					'description' => 'Image token declarations, unchanged unless an error code points at them.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'token' => ['type' => 'string'],
							'kind' => ['type' => 'string', 'enum' => [Package::IMAGE_KIND_GENERATE, Package::IMAGE_KIND_EXTERNAL]],
							'prompt' => ['type' => 'string'],
							'aspect' => ['type' => 'string'],
							'url' => ['type' => 'string'],
							'alt' => ['type' => 'string'],
							// echoed from the input as they are; the portal keeps its own
							'outcome' => ['type' => ['string', 'null']],
							'reason' => ['type' => ['string', 'null']],
							'regenerate' => ['type' => 'boolean'],
						],
						'required' => ['token', 'kind'],
						'additionalProperties' => false,
					],
				],
				'forms' => $this->getFormsSchema(),
				'meta' => [
					'type' => 'object',
					'description' => 'Page meta fields, unchanged unless an error code points at them.',
				],
			],
			'required' => ['entry', 'files', 'images', 'forms', 'meta'],
			'additionalProperties' => false,
		];
	}

	/**
	 * DTO-F1: the CRM form declaration. The portal fields of a bound form (`binding`, `formId`)
	 * are not part of it: the node never binds a form.
	 */
	private function getFormsSchema(): array
	{
		$colors = [];
		foreach (CrmFormColorNormalizer::COLOR_KEYS as $key)
		{
			$colors[$key] = ['type' => 'string'];
		}

		return [
			'type' => 'array',
			'description' => 'CRM form declarations, one per marker token, unchanged unless an error code points '
				. 'at them. A foreign form replaced by a marker gets its declaration here.',
			'items' => [
				'type' => 'object',
				'properties' => [
					'token' => ['type' => 'string'],
					'name' => ['type' => 'string'],
					'fields' => [
						'type' => 'array',
						'items' => [
							'type' => 'object',
							'properties' => [
								'kind' => ['type' => 'string', 'enum' => FormDeclaration::KINDS],
								'label' => ['type' => 'string'],
								'required' => ['type' => 'boolean'],
							],
							'required' => ['kind'],
							'additionalProperties' => false,
						],
					],
					'design' => [
						'type' => 'object',
						'properties' => [
							'colors' => [
								'type' => 'object',
								'description' => 'Hex colors.',
								'properties' => $colors,
								'additionalProperties' => false,
							],
							'font' => [
								'type' => 'object',
								'properties' => [
									'family' => ['type' => 'string'],
									'uri' => ['type' => 'string', 'description' => 'https only.'],
								],
								'required' => ['family'],
								'additionalProperties' => false,
							],
							'style' => ['type' => 'string', 'enum' => FormDeclaration::STYLES],
							'theme' => ['type' => 'string', 'enum' => FormDeclaration::THEMES],
						],
						'additionalProperties' => false,
					],
				],
				'required' => ['token'],
				'additionalProperties' => false,
			],
		];
	}

	/**
	 * Rebuilds a package from the node answer against the currently submitted package.
	 * Null means the answer does not match the contract and the iteration failed.
	 *
	 * Contentless files are restored from the submitted package by path (with an
	 * optional hash check); the node cannot invent binary bytes, so a content string
	 * is accepted for text files only. The declarations are taken from the answer - the node
	 * may add one - with the regenerate flag of the submitted declaration of the same token;
	 * an outcome and a reason in the answer are an echo of the input and are dropped.
	 */
	public function buildPackage(mixed $answer, Package $current): ?Package
	{
		if (!is_array($answer))
		{
			return null;
		}

		$entry = $answer['entry'] ?? null;
		if (
			!is_array($entry)
			|| !is_string($entry['path'] ?? null) || trim((string)$entry['path']) === ''
			|| !is_string($entry['content'] ?? null) || $entry['content'] === ''
		)
		{
			return null;
		}

		if (
			!is_array($answer['files'] ?? null)
			|| !is_array($answer['images'] ?? null)
			|| !is_array($answer['forms'] ?? null)
			|| !is_array($answer['meta'] ?? null)
		)
		{
			return null;
		}

		$currentContents = [];
		foreach ($current->getFiles() as $file)
		{
			$currentContents[$file['path']] = $file['content'];
		}

		$validator = new Validator();
		$package = new Package();
		$package->setEntry((string)$entry['path'], (string)$entry['content']);

		foreach ($answer['files'] as $file)
		{
			if (!is_array($file) || !is_string($file['path'] ?? null))
			{
				return null;
			}
			$path = (string)$file['path'];
			if ($path === '' || $path === (string)$entry['path'])
			{
				return null;
			}

			$content = $file['content'] ?? null;
			if (is_string($content))
			{
				if ($validator->resolveTypeByPath($path) !== 'text')
				{
					return null;
				}
				$package->addAsset($path, $content);
				continue;
			}
			if ($content !== null)
			{
				return null;
			}

			if (!array_key_exists($path, $currentContents))
			{
				return null;
			}
			$hash = $file['hash'] ?? null;
			if ($hash !== null && (!is_string($hash) || $hash !== FileStore::hash($currentContents[$path])))
			{
				return null;
			}
			$package->addAsset($path, $currentContents[$path]);
		}

		// the one-off regenerate flag of the submission is carried over by token: the node does not know it
		$regenerateByToken = [];
		foreach ($current->getImageDeclaration() as $declaration)
		{
			if (!empty($declaration['regenerate']))
			{
				$regenerateByToken[(string)($declaration['token'] ?? '')] = true;
			}
		}
		foreach ($answer['images'] as $image)
		{
			if (!is_array($image))
			{
				return null;
			}
			unset($image['outcome'], $image['reason'], $image['regenerate']);
			if (isset($regenerateByToken[(string)($image['token'] ?? '')]))
			{
				$image['regenerate'] = true;
			}
			$package->addImage($image);
		}

		foreach ($answer['forms'] as $form)
		{
			// a field of an unknown kind is kept: the provisioning step drops it with a warning
			if (FormDeclaration::normalize($form) === null)
			{
				return null;
			}
			$package->addForm($form);
		}

		$package->setMeta($answer['meta']);

		return $package;
	}
}
