# iFound visual analysis

## Scan files: automatic analysis

Open **iFound → Scan Files**, select images or folders, review the selection, and click **Start scan**. There are no scan-option checkboxes. Each image is checked for completed event detection, object detection, and expression analysis. Only missing or unsuccessful analyses run; images with all three completed show **Already scanned**.

Existing event results and manually reviewed classifications are preserved. Older images with completed event detection receive the two new analyses. Successful empty object results and expression results with no face detected count as completed. Failed, unavailable, disabled, and not-yet-requested analyses are retried on the next scan. A failed event service does not prevent the two other analyses from running.

Duplicate scanning and the Duplicate Queue have been removed. Scans do not compare or relocate copies. Previously stored files and records are retained.

In **iFound AI**, select images and click **Detect objects & expressions**. Each image is processed in sequence. Refresh results when scanning finishes, then expand its analysis summary to see labels, confidence, automatic tags, and model availability. Existing event classifications and reviews are retained.

The existing `server-library:analyze` background worker also performs visual analysis for newly queued central-library images. Previously processed images can be scanned with the new iFound action. Keep iFound open until a selected-image scan finishes; already saved results survive navigation, but remaining selected images are not queued. Images only; video frame analysis is not included.

## Models and setup

Use the existing Python inference environment:

```sh
greyhound-hub-2.0/.venv_ai_mac/bin/python -m pip install -r greyhound-hub-2.0/requirements-visual.txt
greyhound-hub-2.0/.venv_ai_mac/bin/python greyhound-hub-2.0/prepare_visual_models.py
php spark migrate
```

Restart the existing AI process after installation. On the project's macOS LaunchAgent deployment:

```sh
launchctl kickstart -k gui/$(id -u)/com.greyhound.convnext-ai
```

Otherwise start `greyhound-hub-2.0/start-ai-server.sh`. On Windows, use the Python environment configured in `start-ai-server.bat` for installation and model preparation.

The `/predict` endpoint continues serving event classification. The additional `/analyze` endpoint accepts the same multipart `media` field and returns independent object and expression results. All image inference runs locally; model preparation downloads public weights once. Models and runtime settings are ignored by Git.

PHP derives `/analyze` from `greyhound.eventModelEndpoint`. To use a separate service, set this in `.env`:

```ini
greyhound.visualModelEndpoint = 'http://127.0.0.1:5001/analyze'
```

Python service environment settings:

- `GREYHOUND_OBJECT_MODEL`: path to a trusted YOLO detection checkpoint, including your future custom university `best.pt`. Default: `models/yolo11n.pt`.
- `GREYHOUND_EXPRESSION_ANALYSIS=off`: disable facial-expression analysis.
- `DEEPFACE_HOME` and `YOLO_CONFIG_DIR`: optional model/config locations; defaults stay inside the project.

Set Python settings in the AI process environment (or its LaunchAgent), not the PHP `.env`. Train university-specific labels separately and evaluate them on unseen photos before replacing the detection checkpoint.

## Results and limitations

- Object tags require at least 50% model confidence. Expression tags use an `Expression:` prefix and require at least 80%. These scores are model outputs, not measured accuracy guarantees.
- Expression labels describe model estimates of visible facial expressions, not a person's actual feelings. No detected face returns `no_face`; unavailable models return `unavailable` rather than fabricated empty detections.
- Automatic tags are additive, deduplicated, and limited to 25 generated tags per image. Existing tags and event reviews are retained. Model version, confidence, normalized bounding boxes, and generated-tag provenance are stored in `media_visual_analysis.result_json`.
- Originals must be accessible through managed storage. Inference uploads are limited to 29 MiB by PHP and images to 40 megapixels by the visual analyzer; inference is unavailable for larger images. Python scales analysis images to fit 1600 pixels before inference.
- If a model is unavailable, prior searchable labels/tags are retained and the latest result displays partial analysis. Select the image again to retry after resolving the model issue.

References: [Ultralytics prediction API](https://docs.ultralytics.com/modes/predict/), [DeepFace](https://github.com/serengil/deepface). Review the model and library licenses for your deployment.

## Validation

```sh
php vendor/bin/phpunit --no-coverage tests/VisualMediaAnalysisTest.php tests/unit/ConvNextIntegrationTest.php tests/unit/IFoundClassificationTest.php tests/unit/IFoundReviewTagsTest.php tests/SidebarNavigationTest.php
greyhound-hub-2.0/.venv_ai_mac/bin/python greyhound-hub-2.0/test_visual_analyzer.py
```

To retry or diagnose one image from the project root: `php spark media:visual-analyze <file-id>`. This saves the same results and tags as the iFound button.
