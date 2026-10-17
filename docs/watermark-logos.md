# Custom watermark logos

Admins and super admins can click the **+ tile in Watermarking → Default watermark’s logo grid** to open the Add logo popup, enter a name, and upload a PNG, JPG, or WebP image. The limit is 5 MB and 2048 × 2048 pixels. Transparent PNGs retain their transparent background.

The new logo is selected after upload and is available alongside the built-in department logos for previews, individual downloads, bulk downloads, and saved default settings. Uploading a logo does not change the saved default or modify any original media.

Custom logos are stored as normalized PNGs with a name in `writable/uploads/watermark-logos/CUSTOM-*/`. Include this directory in backups and preserve it during deployments. No database migration is needed. Uploaded names and original filenames are not used as storage paths, and logo files are served through the existing authenticated logo endpoint.

The POST upload endpoint is `/workspace/watermarking/logos`, requires CSRF protection and an admin or super-admin session, and records a `WATERMARK_LOGO_UPLOAD` audit event. PHP GD must support the uploaded format.
