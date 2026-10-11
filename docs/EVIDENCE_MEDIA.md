# Work Order evidence media

## Capture and accepted files

Work Order request, assessment, work-update, and session forms use a shared photo picker. **Take Photo** renders an `image/*` input with `capture="environment"`; supported mobile browsers offer the rear camera, while other browsers use their normal picker. **Choose Photos/Files** supports multiple selection. JavaScript shows local pre-upload previews and permits removal, but forms remain normal multipart submissions and server validation is authoritative.

Accepted images are JPEG, PNG, and WebP. Existing execution/request paths may also accept MP4 video. Each action accepts the configured `WORK_ORDER_ATTACHMENT_MAX_COUNT` (default 3) and `WORK_ORDER_ATTACHMENT_MAX_SIZE_KB` (default 10,240 KB) per file. Videos retain their existing private-file path and are never passed to image processing.

## Image normalization

Normalization occurs server-side using PHP GD. The uploaded original is decoded only after MIME, dimensions, and pixel-count checks. `WORK_ORDER_IMAGE_MAX_INPUT_DIMENSION` defaults to 12,000 pixels per side and `WORK_ORDER_IMAGE_MAX_PIXELS` defaults to 40,000,000 pixels to bound decompression work.

The authoritative stored photo is a normalized version with a maximum longest edge of `WORK_ORDER_IMAGE_MAX_DIMENSION` (default 1,920 px). Smaller images are not enlarged. JPEG and WebP use `WORK_ORDER_IMAGE_QUALITY` (default 82); PNG uses `WORK_ORDER_PNG_COMPRESSION` (default 6). Input format is retained for interoperability. JPEG EXIF orientation is applied before sizing. Re-encoding strips EXIF—including GPS and camera metadata—so audit identity comes from the authenticated uploader, Work Order relationship, and server timestamp rather than client metadata. Enormous originals are not retained.

Every normalized image receives a separate same-format thumbnail capped at `WORK_ORDER_THUMBNAIL_MAX_DIMENSION` (default 480 px). Dimensions and normalized byte size are stored on the existing attachment record. Generated names use server-side ULIDs; the original client filename remains metadata only. Files and database records participate in `StoredFileTransaction`, so a processing or database failure cleans up new variants rather than leaving a half-written record.

Use `evidence:normalize-existing --dry-run` to count legacy images without thumbnails, then `evidence:normalize-existing` to safely normalize them. Each record is updated only after its new full and thumbnail variants are written; the old private file is removed after commit.

## Private delivery and galleries

Full images and thumbnails remain on Laravel's private local disk. Both variants pass through attachment-to-Work-Order binding and the existing Work Order authorization rules. Responses use the verified MIME type, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, and private/no-store caching. Explicit downloads use a separate protected route.

Requester attachments are visible to the requester and other users who may view the Work Order. Assessment evidence remains limited to active assignees and users with assessment visibility. Execution evidence remains limited to active assignees and execution-authorized management unless an existing record is explicitly requester-visible. Knowing an attachment ULID never grants access.

The detail page groups media into Requester Photos, Assessment Evidence, Work Evidence, and Completion Evidence. Galleries load protected thumbnails lazily. Clicking a photo opens the full protected image in an inline lightbox with previous/next controls, a position counter, keyboard navigation, and mobile swipe. Videos and other non-image files retain explicit protected view/download actions.
