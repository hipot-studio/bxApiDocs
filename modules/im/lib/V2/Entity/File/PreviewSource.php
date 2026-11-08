<?php

namespace Bitrix\Im\V2\Entity\File;

/**
 * What a file is shown by. The value never reaches a client: it only names the branch chosen by
 * {@see FileItem::getPreviewSource()} for the consumers that build a link or a response out of it.
 */
enum PreviewSource: string
{
	/** The file itself: an animation, or an image that already fits the threshold. */
	case Original = 'original';

	/** An image above the threshold, scaled down proportionally. */
	case ResizedImage = 'resizedImage';

	/** Not an image, but a preview was generated for it. */
	case GeneratedPreview = 'generatedPreview';

	/**
	 * Nothing to show. A link stays empty here, while the direct download answers with the original:
	 * the difference lives in the consumers, not in the branch itself.
	 */
	case None = 'none';
}
