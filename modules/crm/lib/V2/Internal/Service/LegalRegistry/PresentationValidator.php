<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\LedgerRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogVersion;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionManifest;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\TrustedSubmissionContext;
use Bitrix\Main\Security\Sign\Signer;

final class PresentationValidator
{
	private const PURPOSE = 'crm.legal-registry.presentation.v1';

	/** Only trusted PHP presentation adapters may call this after obtaining current storage snapshots. */
	public function issue(SubmissionManifest $manifest, array $source, array $bindings, array $versions)
		: TrustedSubmissionContext
	{
		$this->validateCurrent($manifest, $source, $bindings, $versions);
		$payload = $this->payload($manifest);
		$payload['sourceId'] = (int)$source['ID'];
		$payload['versions'] = [];
		foreach ($versions as $version)
		{
			$payload['versions'][(string)$version->id] = $version->contentHash;
		}
		$token = (new Signer())->sign(base64_encode(LedgerRepository::canonicalJson($payload)), self::PURPOSE);

		return new TrustedSubmissionContext($manifest->formId, $token);
	}

	public function verify(TrustedSubmissionContext $context, SubmissionManifest $manifest): array
	{
		try
		{
			$unsigned = (new Signer())->unsign($context->presentationToken, self::PURPOSE);
			$payload = json_decode(base64_decode($unsigned, true), true, 32, JSON_THROW_ON_ERROR);
			$expected = $this->payload($manifest);
			if ($context->formId !== $manifest->formId || !is_array($payload)
				|| !is_int($payload['sourceId'] ?? null) || $payload['sourceId'] <= 0
				|| !is_array($payload['versions'] ?? null))
			{
				throw new \UnexpectedValueException();
			}
			foreach ($expected as $key => $value)
			{
				if (($payload[$key] ?? null) !== $value) { throw new \UnexpectedValueException(); }
			}

			return $payload;
		}
		catch (\Throwable) { throw new \UnexpectedValueException('STALE_PRESENTATION'); }
	}

	public function validateCurrent(SubmissionManifest $manifest, array $source, array $bindings, array $versions): void
	{
		$this->payload($manifest);
		if (($source['STATE'] ?? null) !== 'active' || (int)($source['FORM_ID'] ?? 0) !== $manifest->formId
			|| (int)($source['REVISION'] ?? 0) !== $manifest->sourceRevision)
		{
			throw new \UnexpectedValueException('STALE_PRESENTATION');
		}
		$byDocument = [];
		foreach ($bindings as $binding)
		{
			if ($binding['DATE_END'] === null && (int)$binding['SOURCE_ID'] === (int)$source['ID'])
			{
				$byDocument[(int)$binding['DOCUMENT_ID']] = $binding;
			}
		}
		$byVersion = [];
		foreach ($versions as $version)
		{
			if (!$version instanceof CatalogVersion) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
			$byVersion[$version->id] = $version;
		}
		$answers = [];
		foreach ($manifest->items as $item)
		{
			$binding = $byDocument[$item->documentId] ?? null;
			$version = $byVersion[$item->versionId] ?? null;
			if ($binding === null || (int)$binding['VERSION_ID'] !== $item->versionId
				|| (int)$binding['REVISION'] !== $item->bindingRevision || $version === null
				|| $version->documentId !== $item->documentId || !in_array($version->state, ['published', 'archived'], true))
			{
				throw new \UnexpectedValueException('STALE_PRESENTATION');
			}
			try { $rendered = (new VersionRenderer())->render($version, $item->substitutions); }
			catch (\Throwable) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
			if ($rendered['text'] !== $item->effectiveText || $rendered['label'] !== $item->effectiveLabel
				|| $rendered['textHash'] !== $item->textHash)
			{
				throw new \InvalidArgumentException('INVALID_PAYLOAD');
			}
			$answers[$item->documentId] = $item->answer;
		}
		foreach ($byDocument as $documentId => $binding)
		{
			if ($binding['REQUIRED'] === 'Y' && ($answers[$documentId] ?? null) !== 'accepted')
			{
				throw new \InvalidArgumentException('INVALID_PAYLOAD');
			}
		}
	}

	public static function identity(SubmissionManifest $manifest): array
	{
		$items = [];
		foreach ($manifest->items as $item)
		{
			$row = get_object_vars($item);
			$row['substitutions'] = (object)$item->substitutions;
			$items[] = $row;
		}
		usort($items, static fn(array $a, array $b): int => $a['documentId'] <=> $b['documentId']);

		return ['submissionKeyBase64' => base64_encode($manifest->submissionKey), 'formId' => $manifest->formId,
			'sourceRevision' => $manifest->sourceRevision, 'items' => $items];
	}

	public static function identityHash(SubmissionManifest $manifest): string
	{
		return hash('sha256', LedgerRepository::canonicalJson(self::identity($manifest)));
	}

	private function payload(SubmissionManifest $manifest): array
	{
		try
		{
			$identity = self::identity($manifest);
			$identity['presentedAt'] = $manifest->presentedAt->format('Y-m-d\TH:i:s.uP');
			$json = LedgerRepository::canonicalJson($identity);
			if (strlen($manifest->submissionKey) > 255 || count($manifest->items) > 100 || strlen($json) > 1048576)
			{
				throw new \InvalidArgumentException();
			}

			return ['formId' => $manifest->formId, 'sourceRevision' => $manifest->sourceRevision,
				'manifestHash' => hash('sha256', $json), 'presentedAt' => $identity['presentedAt']];
		}
		catch (\Throwable) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
	}
}
