# iFound classification flow

Before human review, iFound uses the following decision for each image:

1. AI confidence below **80%**, an unavailable prediction, or a filename-only
   suggestion → **Needs review**.
2. AI confidence **at least 80%** → compare the prediction with the folder's
   dominant event:
   - Same event → **Dominant**.
   - Another event → **Different**.

The dominant event is the most frequent confident AI prediction among active
images directly in the same folder. Root-library images form their own group.
Videos, deleted files, low-confidence results, and keyword guesses do not vote.
There is no dominant event when the leading counts tie or no qualifying
predictions exist; affected images show **Needs review**.

The comparison considers the full folder, independently of the current search,
page, or selection. It is computed when iFound loads, so existing records use
the new flow without a migration or reclassification. Kasadyaan subcategories
are compared as separate event labels.

Results are sorted **Needs review → Different → Dominant → Reviewed** before
pagination, with the newest files first within each group. Non-image files
appear last.

The **AI prediction** comes from stored model evidence where available. Manual
review updates **Final classification** and tags. Once saved, the **AI Result**
column always shows **Reviewed**, regardless of confidence or folder dominance.
The final classification displays the saved event without a duplicate Reviewed
note. The original AI prediction and confidence remain visible for reference
and still determine folder dominance.
A new review requirement (`needs_review = 1`) takes precedence over an older
review timestamp. Existing saved reviews automatically use this behavior.

`GreyhoundAi::reviewThreshold` is 80. New detection records use that threshold
for their confidence review flag. A failed inference may retain a filename
suggestion, but its AI confidence is zero and it always needs review.

Implementation: `IFoundClassificationService`, `EventDetectionService`,
`WorkspaceController::ifound`, and `app/Views/workspace/ifound.php`.

## Analyze selected images

Select image checkboxes in iFound and click **Analyze selected images**. The
CSRF-protected `POST workspace/ifound/analyze-selected` accepts up to 100
file IDs and queues active images with no `ai_event_detections` record using
`server_analysis_jobs`. Existing predictions and manual reviews are skipped;
non-images and deleted records are also skipped. Images without a linked
active `server_media` identity are reported as unavailable for this queue.
Repeated requests do not duplicate jobs or reset pending job attempts. Failed
jobs without a detection can be selected again to reset their retry count.

The existing `server-library:analyze` worker checks the queue every minute on
this installation, processes up to 20 jobs per batch, and writes predictions,
confidence, server metadata and passports. It requires the AI service and
accessible original files. Low-confidence predictions remain for manual review.
The page polls the authorized `GET workspace/ifound/analysis-status` endpoint
and offers **Refresh results** when processing finishes. It does not reload an
open review. Navigation stops progress polling; queued work continues.
After ten minutes the progress display offers a manual refresh rather than
polling indefinitely. Failed jobs remain distinguishable from completed jobs.

Verification: 10 PHP tests covering queue behavior, permissions, sidebar pages
and ConvNeXt integration; 18 JavaScript tests covering the selected-analysis
button, CSRF refresh, progress/failures, cleanup and AJAX navigation. Live AI
health and worker log checked; no user images were analyzed as a test.
