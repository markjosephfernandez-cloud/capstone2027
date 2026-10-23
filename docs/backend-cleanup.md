# Backend cleanup review

This pass covers the PHP backend: web/API controllers, services, models, libraries,
filters, and CLI commands. It builds on the dashboard cleanup and preserves the
uncommitted work that was already present. Views, routes, schema migrations,
configuration values, frontend JavaScript, and the Expo application were not edited
in this pass.

The main readability change expands compact control flow into explicit blocks.
Validation expressions, authorization checks, response messages, query ordering,
and file-operation ordering are preserved. PHPDoc tags now occupy separate lines;
stale scaffold comments, an obsolete watermark parameter description, and two
unused imports were removed. Selected small controllers have native response types.

The mobile watch-history row lock now lives in `FileModel::lockForWatchHistory()`.
The controller still opens the transaction first and passes that same database
connection to the model. The SQL and its bound file ID are unchanged. The method
requires an open transaction and retains its existing MySQL-specific behavior.

Maintenance rules worth preserving:

- `RolePolicy::role()` falls back to the department role for unknown or missing
  roles. Do not replace this fallback during a formatting cleanup.
- Dashboard storage availability depends on resolving the storage root even for
  accounts whose displayed usage comes from visible file sizes.
- `TokenService::publicUser()` whitelists response fields. Credential fields must
  remain excluded.
- Audit writes retain the request parameter for callers but store no IP address.
- `RecentViewCsrf` restores the previous regeneration setting in `finally`.
- Failed analysis jobs may be reset; pending or running jobs keep their attempts.

Verification on PHP 8.4.26:

- Syntax checks passed for all 81 PHP files changed in this pass.
- Parsed structures match the pre-cleanup snapshot for 74 files, excluding comments
  and empty statements. The remaining seven were reviewed for response types,
  import removal, and moving the row-lock query into the model.
- `git diff --check` passed.
- `./vendor/bin/phpunit`: 381 tests, 2,797 assertions, four failures and one warning.
  The same failures occurred before this pass and after the mechanical cleanup:
  `AjaxAppTest::testBatchUploadRefreshesLibraryThroughAjax`,
  `AppPublishingTest::testFileManagementConfirmationPostsWithoutMetadataFields`,
  `DistributionViewTest::testFocusedDepartmentRequestHasDeepLinkTargetAndHighlight`,
  and `MediaDuplicateViewTest::testFileManagementOmitsRemovedDuplicateQueue`.
- The warning is `No code coverage driver available`; the suite exits unsuccessfully.
- Live MySQL concurrency, network-share operations, external AI workers, and browser
  flows were not independently exercised. Parsed equivalence and the existing test
  suite do not replace those integration checks.

The large workspace controller still combines several workflows. Splitting it into
new services would require separate behavioral coverage and is beyond this
readability pass. Existing failing tests remain unresolved; no assertions were
weakened to make the suite pass.

Files changed in this pass (the earlier dashboard controller/test edits are separate):

**app/Commands**

- [AnalyzeVisualImage.php](../app/Commands/AnalyzeVisualImage.php)
- [CentralAnalysisCommand.php](../app/Commands/CentralAnalysisCommand.php)
- [CentralMigrationCommand.php](../app/Commands/CentralMigrationCommand.php)
- [CleanMediaTagsCommand.php](../app/Commands/CleanMediaTagsCommand.php)
- [RecoverDepartmentMediaCommand.php](../app/Commands/RecoverDepartmentMediaCommand.php)
- [SeparateDepartmentLibraryCommand.php](../app/Commands/SeparateDepartmentLibraryCommand.php)
- [ServerLibraryIndexCommand.php](../app/Commands/ServerLibraryIndexCommand.php)

**app/Controllers/Api**

- [ApiController.php](../app/Controllers/Api/ApiController.php)
- [AuthController.php](../app/Controllers/Api/AuthController.php)
- [FileController.php](../app/Controllers/Api/FileController.php)
- [FolderController.php](../app/Controllers/Api/FolderController.php)
- [MobileFeaturedController.php](../app/Controllers/Api/MobileFeaturedController.php)
- [MobileFeedController.php](../app/Controllers/Api/MobileFeedController.php)
- [MobileHistoryController.php](../app/Controllers/Api/MobileHistoryController.php)
- [MobileInteractionController.php](../app/Controllers/Api/MobileInteractionController.php)
- [MobilePostController.php](../app/Controllers/Api/MobilePostController.php)
- [MobileProfileController.php](../app/Controllers/Api/MobileProfileController.php)
- [MobileSearchController.php](../app/Controllers/Api/MobileSearchController.php)

**app/Controllers**

- [BaseController.php](../app/Controllers/BaseController.php)

**app/Controllers/Web**

- [AddonsController.php](../app/Controllers/Web/AddonsController.php)
- [AdminController.php](../app/Controllers/Web/AdminController.php)
- [AuthController.php](../app/Controllers/Web/AuthController.php)
- [DistributionController.php](../app/Controllers/Web/DistributionController.php)
- [DistributionServerController.php](../app/Controllers/Web/DistributionServerController.php)
- [NotificationController.php](../app/Controllers/Web/NotificationController.php)
- [PassportController.php](../app/Controllers/Web/PassportController.php)
- [PrivacyController.php](../app/Controllers/Web/PrivacyController.php)
- [SearchSuggestionsController.php](../app/Controllers/Web/SearchSuggestionsController.php)
- [ServerLibraryActionsController.php](../app/Controllers/Web/ServerLibraryActionsController.php)
- [ServerLibraryController.php](../app/Controllers/Web/ServerLibraryController.php)
- [VisualAnalysisController.php](../app/Controllers/Web/VisualAnalysisController.php)
- [WorkspaceController.php](../app/Controllers/Web/WorkspaceController.php)

**app/Filters**

- [RecentViewCsrf.php](../app/Filters/RecentViewCsrf.php)

**app/Libraries**

- [AssembledUpload.php](../app/Libraries/AssembledUpload.php)
- [DisplayTime.php](../app/Libraries/DisplayTime.php)
- [MediaMimeType.php](../app/Libraries/MediaMimeType.php)
- [MediaStreamResponse.php](../app/Libraries/MediaStreamResponse.php)
- [ParallelFileCopier.php](../app/Libraries/ParallelFileCopier.php)
- [RolePolicy.php](../app/Libraries/RolePolicy.php)

**app/Models**

- [FileModel.php](../app/Models/FileModel.php)
- [FolderModel.php](../app/Models/FolderModel.php)
- [MediaReportModel.php](../app/Models/MediaReportModel.php)

**app/Services**

- [AppPublishingService.php](../app/Services/AppPublishingService.php)
- [AuditService.php](../app/Services/AuditService.php)
- [CameraImagePreviewService.php](../app/Services/CameraImagePreviewService.php)
- [CentralDistributionService.php](../app/Services/CentralDistributionService.php)
- [CentralMediaService.php](../app/Services/CentralMediaService.php)
- [CentralMigrationCleanup.php](../app/Services/CentralMigrationCleanup.php)
- [CentralMigrationService.php](../app/Services/CentralMigrationService.php)
- [ChunkUploadService.php](../app/Services/ChunkUploadService.php)
- [DashboardReadService.php](../app/Services/DashboardReadService.php)
- [DepartmentLibraryService.php](../app/Services/DepartmentLibraryService.php)
- [DepartmentMediaRecoveryService.php](../app/Services/DepartmentMediaRecoveryService.php)
- [DistributionService.php](../app/Services/DistributionService.php)
- [EventDetectionService.php](../app/Services/EventDetectionService.php)
- [IFoundAnalysisQueue.php](../app/Services/IFoundAnalysisQueue.php)
- [IFoundClassificationService.php](../app/Services/IFoundClassificationService.php)
- [ImageSimilarityService.php](../app/Services/ImageSimilarityService.php)
- [PassportService.php](../app/Services/PassportService.php)
- [SelectedImageScan.php](../app/Services/SelectedImageScan.php)
- [ServerDistributionPicker.php](../app/Services/ServerDistributionPicker.php)
- [ServerLibraryCatalog.php](../app/Services/ServerLibraryCatalog.php)
- [ServerLibraryCatalogWriter.php](../app/Services/ServerLibraryCatalogWriter.php)
- [ServerLibraryEditor.php](../app/Services/ServerLibraryEditor.php)
- [ServerLibraryIndexer.php](../app/Services/ServerLibraryIndexer.php)
- [ServerLibraryPresentation.php](../app/Services/ServerLibraryPresentation.php)
- [ServerLibrarySearchIndex.php](../app/Services/ServerLibrarySearchIndex.php)
- [ServerLibraryService.php](../app/Services/ServerLibraryService.php)
- [ServerLibraryTransferService.php](../app/Services/ServerLibraryTransferService.php)
- [ServerLibraryUploadService.php](../app/Services/ServerLibraryUploadService.php)
- [ServerScanService.php](../app/Services/ServerScanService.php)
- [ServerUploadAuditService.php](../app/Services/ServerUploadAuditService.php)
- [StorageService.php](../app/Services/StorageService.php)
- [ThumbnailService.php](../app/Services/ThumbnailService.php)
- [TokenService.php](../app/Services/TokenService.php)
- [TrashService.php](../app/Services/TrashService.php)
- [VideoMimeType.php](../app/Services/VideoMimeType.php)
- [VideoPreviewService.php](../app/Services/VideoPreviewService.php)
- [VisualMediaAnalysisService.php](../app/Services/VisualMediaAnalysisService.php)
- [WatermarkImageRenderer.php](../app/Services/WatermarkImageRenderer.php)
- [WatermarkService.php](../app/Services/WatermarkService.php)

