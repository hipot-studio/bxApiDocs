<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Disk;

use Bitrix\Crm\Integration\Disk\MailTemplateConnector;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Uf\StubConnector;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Uri;

class MailTemplateEditorAttachmentProvider
{
	/** @param list<string> $files */
	public function validateForSave(string $bodyHtml, array $files, int $templateId, int $userId): Result
	{
		if (!Loader::includeModule('disk'))
		{
			return (new Result())->addError(new Error('SAVE_FAILED', 'SAVE_FAILED'));
		}

		$userFields = $GLOBALS['USER_FIELD_MANAGER']->getUserFields(
			'CRM_MAIL_TEMPLATE',
			$templateId,
			false,
			$userId,
			['UF_ATTACHMENT'],
		);
		$field = $userFields['UF_ATTACHMENT'] ?? null;
		if (($field['USER_TYPE_ID'] ?? null) !== 'disk_file' || ($field['MULTIPLE'] ?? null) !== 'Y')
		{
			throw new SystemException('Mail template attachment field is unavailable.');
		}

		$savedIds = array_fill_keys(array_map('strval', (array)($field['VALUE'] ?? [])), true);
		$attachedIds = [];
		$objectIds = [];
		foreach ($files as $id)
		{
			if (!$this->isFileId($id))
			{
				return $this->validationError('files');
			}
			if (str_starts_with($id, 'n'))
			{
				$objectIds[] = (int)substr($id, 1);
			}
			else
			{
				if ($templateId <= 0 || !isset($savedIds[$id]))
				{
					return $this->validationError('files');
				}
				$attachedIds[] = (int)$id;
			}
		}

		$attachments = $this->loadAttachments($attachedIds);
		foreach ($attachedIds as $id)
		{
			$attachment = $attachments[$id] ?? null;
			if (!$this->belongsToTemplate($attachment, $templateId, true))
			{
				return $this->validationError('files');
			}
		}
		foreach (array_chunk($objectIds, 300) as $batch)
		{
			$objects = File::getModelList([
				'filter' => ['=ID' => $batch, '=TYPE' => ObjectTable::TYPE_FILE],
				'with' => ['STORAGE'],
			]);
			$readableIds = [];
			foreach ($objects as $file)
			{
				$storage = $file->getStorage();
				if ($file->isDeleted() || $storage === null || !$file->canRead($storage->getSecurityContext($userId)))
				{
					return $this->validationError('files');
				}
				$readableIds[] = (int)$file->getId();
			}
			if (array_diff($batch, $readableIds) !== [])
			{
				return $this->validationError('files');
			}
		}

		$bodyHtml = $this->normalizeBody($bodyHtml, $files);
		if ($bodyHtml === null)
		{
			return $this->validationError('bodyHtml');
		}

		$result = new Result();
		$result->setData([
			'bodyHtml' => $bodyHtml,
			'files' => $this->prepareInlineOrder($bodyHtml, array_values(array_unique($files)), $attachments),
		]);

		return $result;
	}

	/**
	 * @param list<string> $files
	 * @param array<int, AttachedObject> $attachments
	 * @return list<string>
	 */
	private function prepareInlineOrder(string $bodyHtml, array $files, array $attachments): array
	{
		preg_match_all('/bxacid:n([1-9][0-9]*)(?![a-z0-9])/', $bodyHtml, $matches);
		$inlineIds = array_fill_keys($matches[1], true);
		$precedingObjects = [];
		$hasCollision = false;
		$newIds = [];
		$retainedIds = [];
		foreach ($files as $id)
		{
			if (str_starts_with($id, 'n'))
			{
				$objectId = substr($id, 1);
				$newIds[] = (int)$objectId;
				if (isset($inlineIds[$objectId]))
				{
					for ($length = 1; $length <= strlen($objectId); $length++)
					{
						if (isset($precedingObjects[substr($objectId, 0, $length)]))
						{
							$hasCollision = true;
						}
					}
				}
			}
			else
			{
				$objectId = (string)$attachments[$id]->getObjectId();
				$retainedIds[] = $id;
			}
			$precedingObjects[$objectId] = true;
		}
		if (!$hasCollision)
		{
			return $files;
		}

		// The legacy writer replaces numeric prefixes without a token boundary.
		rsort($newIds, SORT_NUMERIC);

		return [...array_map(static fn(int $id): string => 'n' . $id, $newIds), ...$retainedIds];
	}

	/**
	 * @param list<string> $expectedFiles
	 * @param list<string> $actualFiles
	 */
	public function assertSaved(
		int $templateId,
		string $expectedBody,
		array $expectedFiles,
		string $actualBody,
		array $actualFiles,
	): void
	{
		if (
			!Loader::includeModule('disk')
			|| count($actualFiles) !== count($expectedFiles)
			|| count(array_unique($actualFiles)) !== count($actualFiles)
		)
		{
			throw new SystemException('Mail template attachments could not be saved.');
		}

		$remaining = array_fill_keys($expectedFiles, true);
		$replacements = [];
		$attachments = $this->loadAttachments($actualFiles);
		foreach ($actualFiles as $id)
		{
			$attachment = $attachments[$id] ?? null;
			if (!$this->belongsToTemplate($attachment, $templateId))
			{
				throw new SystemException('Mail template attachment has an invalid binding.');
			}
			if (isset($remaining[$id]))
			{
				unset($remaining[$id]);

				continue;
			}
			$objectId = 'n' . $attachment->getObjectId();
			if (!isset($remaining[$objectId]))
			{
				throw new SystemException('Mail template attachment does not match the selection.');
			}
			$replacements[$objectId] = $id;
			unset($remaining[$objectId]);
		}
		if ($remaining !== [])
		{
			throw new SystemException('Mail template attachment selection is incomplete.');
		}

		$expectedBody = preg_replace_callback(
			'/bxacid:(n?[1-9][0-9]*)(?![a-z0-9])/i',
			static fn(array $match): string => 'bxacid:' . ($replacements[$match[1]] ?? $match[1]),
			$expectedBody,
		);
		if ($actualBody !== $expectedBody)
		{
			throw new SystemException('Mail template inline attachments could not be saved.');
		}
	}

	private function normalizeBody(string $bodyHtml, array $files): ?string
	{
		$selection = array_fill_keys($files, true);
		$invalid = false;
		$bodyHtml = preg_replace('/https?:\/\/bxacid:/i', 'bxacid:', $bodyHtml);
		$bodyHtml = preg_replace_callback(
			'~(?:https?://|/)[^\s"\'<>]+~i',
			function (array $match) use ($selection, &$invalid): string {
				$uri = new Uri(html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				parse_str($uri->getQuery(), $query);
				$id = $query['__bxacid'] ?? null;
				if ($id === null && $uri->getPath() === '/bitrix/tools/disk/uf.php')
				{
					$id = $query['attachedId'] ?? null;
				}
				if ($id === null)
				{
					return $match[0];
				}
				if (
					!$this->isFileId($id)
					|| !isset($selection[$id])
					|| (isset($query['attachedId']) && $query['attachedId'] !== $id)
				)
				{
					$invalid = true;

					return $match[0];
				}

				return 'bxacid:' . $id;
			},
			$bodyHtml,
		);
		$bodyHtml = preg_replace_callback(
			'/bxacid:(n?[1-9][0-9]*)(?![a-z0-9])/i',
			static function (array $match) use ($selection, &$invalid): string {
				$id = strtolower($match[1]);
				if (!isset($selection[$id]))
				{
					$invalid = true;
				}

				return 'bxacid:' . $id;
			},
			$bodyHtml,
		);
		$withoutMarkers = preg_replace('/bxacid:n?[1-9][0-9]*(?![a-z0-9])/i', '', $bodyHtml);

		return $invalid || stripos($withoutMarkers, 'bxacid:') !== false ? null : $bodyHtml;
	}

	/** @return array<int, AttachedObject> */
	private function loadAttachments(array $ids): array
	{
		$result = [];
		foreach (array_chunk($ids, 300) as $batch)
		{
			foreach (AttachedObject::getModelList(['filter' => ['=ID' => $batch]]) as $attachment)
			{
				$result[(int)$attachment->getId()] = $attachment;
			}
		}

		return $result;
	}

	private function belongsToTemplate(?AttachedObject $attachment, int $templateId, bool $allowLegacy = false): bool
	{
		if ($attachment === null || (int)$attachment->getEntityId() !== $templateId)
		{
			return false;
		}

		return ($attachment->getModuleId() === 'crm' && $attachment->getEntityType() === MailTemplateConnector::class)
			|| (
				$allowLegacy
				&& $attachment->getModuleId() === Driver::INTERNAL_MODULE_ID
				&& $attachment->getEntityType() === StubConnector::class
			);
	}

	private function isFileId(mixed $id): bool
	{
		return is_string($id)
			&& preg_match('/^n?[1-9][0-9]*$/D', $id) === 1
			&& (string)(int)ltrim($id, 'n') === ltrim($id, 'n');
	}

	private function validationError(string $field): Result
	{
		return (new Result())->addError(new Error('VALIDATION_ERROR', 'VALIDATION_ERROR', ['field' => $field]));
	}

	/**
	 * @param list<string> $files
	 * @return array{bodyHtml: string, previewFiles: list<string>}
	 */
	public function prepare(string $bodyHtml, array $files, int $templateId, int $userId): array
	{
		$result = ['bodyHtml' => $bodyHtml, 'previewFiles' => []];
		if ($templateId <= 0 || $userId <= 0 || $files === [] || !Loader::includeModule('disk'))
		{
			return $result;
		}

		$ids = [];
		foreach ($files as $id)
		{
			if (preg_match('/^[1-9][0-9]*$/D', $id) === 1 && (string)(int)$id === $id)
			{
				$ids[$id] = (int)$id;
			}
		}

		$manager = Driver::getInstance()->getUserFieldManager();
		foreach (array_chunk($ids, 300) as $batch)
		{
			$manager->loadBatchAttachedObject($batch);
		}

		foreach ($ids as $id)
		{
			try
			{
				$attachment = $manager->getAttachedObjectById($id);
				if (!$this->isReadable($attachment, $templateId, $userId))
				{
					continue;
				}

				$url = (new Uri(Driver::getInstance()->getUrlManager()->getUrlUfController('show', [
					'attachedId' => $id,
				])))
					->addParams(['__bxacid' => $id])
					->getUri()
				;
				$pattern = '/bxacid:' . $id . '(?![0-9])/';
				$result['bodyHtml'] = preg_replace_callback(
					$pattern,
					static fn(): string => $url,
					$result['bodyHtml'],
				);
				$result['previewFiles'][] = (string)$id;
			}
			catch (SystemException)
			{
				continue;
			}
		}

		return $result;
	}

	private function isReadable(?AttachedObject $attachment, int $templateId, int $userId): bool
	{
		if (
			$attachment === null
			|| $attachment->getModuleId() !== 'crm'
			|| $attachment->getEntityType() !== MailTemplateConnector::class
			|| (int)$attachment->getEntityId() !== $templateId
			|| !$attachment->getConnector()->canRead($userId)
		)
		{
			return false;
		}

		$file = $attachment->getObject();
		if (!$file instanceof File || $file->isDeleted())
		{
			return false;
		}

		$storage = $file->getStorage();

		return $storage !== null && $file->canRead($storage->getSecurityContext($userId));
	}
}
