import unittest
from io import BytesIO
from unittest.mock import patch
from PIL import Image
import visual_analyzer


class VisualAnalyzerTest(unittest.TestCase):
    def image_bytes(self):
        stream = BytesIO()
        Image.new("RGB", (30, 30), "white").save(stream, format="PNG")
        return stream.getvalue()

    def test_model_failure_does_not_hide_other_task_results(self):
        with patch.object(visual_analyzer, "analyze_objects", side_effect=RuntimeError("offline")), \
             patch.object(visual_analyzer, "analyze_expressions", return_value={"status": "no_face", "items": []}):
            with self.assertLogs(level="ERROR"):
                result = visual_analyzer.analyze(self.image_bytes())
        self.assertEqual(result["objects"]["status"], "unavailable")
        self.assertEqual(result["expressions"]["status"], "no_face")

    def test_only_selected_model_runs(self):
        for chosen, skipped in (("objects", "expressions"), ("expressions", "objects")):
            with patch.object(visual_analyzer, "analyze_" + chosen, return_value={"status": "complete", "items": []}) as run, \
                 patch.object(visual_analyzer, "analyze_" + skipped) as ignored:
                result = visual_analyzer.analyze(self.image_bytes(), [chosen])
                run.assert_called_once()
                ignored.assert_not_called()
                self.assertEqual(result[skipped]["status"], "not_requested")

    def test_bad_image_is_rejected_before_inference(self):
        with patch.object(visual_analyzer, "analyze_objects") as detector:
            with self.assertRaises(Exception):
                visual_analyzer.analyze(b"not an image")
            detector.assert_not_called()

    def test_expressions_can_be_disabled(self):
        with patch.dict(visual_analyzer.os.environ, {"GREYHOUND_EXPRESSION_ANALYSIS": "off"}):
            self.assertEqual(visual_analyzer.analyze_expressions(Image.new("RGB", (30, 30)))["status"], "disabled")


if __name__ == "__main__":
    unittest.main()
