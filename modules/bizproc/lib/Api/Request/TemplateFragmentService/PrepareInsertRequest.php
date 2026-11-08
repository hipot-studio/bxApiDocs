<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateFragmentService;

use Bitrix\Bizproc\Public\Service\Storage\StorageAuthorizationContext;

/**
 * Everything the preparation of an insertion is judged by belongs to the RECIPIENT: the document type its
 * nodes have to be applicable to, the person doing the insertion, and the published baseline the storage
 * references of the fragment are authorised against. The document type of the source lies in the envelope
 * and is answered back for the text of the warning alone.
 */
final class PrepareInsertRequest
{
	/**
	 * @param array $targetDocumentType the triple of the document type of the recipient, never of the source
	 * @param StorageAuthorizationContext|\Closure|null $storageContext the published baseline of the
	 *        recipient, assembled by the caller or as a callable assembling it on demand. A callable is what
	 *        the caller hands in when assembling costs a read of the published template of the recipient: the
	 *        barrier asks for it at the first node consuming storage and never for a fragment carrying none,
	 *        so the fragment is read and authorised before anything is paid for the recipient. Left out - and
	 *        for a callable answering with nothing - the barrier takes the empty baseline and authorises no
	 *        storage reference at all, which is what a recipient with no published version honestly has.
	 * @param bool $complexNodeConnectionsAvailable the rollout verdict of the editor, so the blocks come
	 *        back in the very shape the loading of a scheme hands them over in
	 */
	public function __construct(
		public readonly int $fragmentId,
		public readonly int $userId,
		public readonly array $targetDocumentType,
		public readonly StorageAuthorizationContext|\Closure|null $storageContext = null,
		public readonly bool $complexNodeConnectionsAvailable = false,
	)
	{}
}
