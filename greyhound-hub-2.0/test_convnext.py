"""Run with: .venv_ai_mac/bin/python -m unittest test_convnext -v"""

from io import BytesIO
from pathlib import Path
import unittest
from unittest.mock import patch

from PIL import Image, UnidentifiedImageError
import torch

from event_classifier import EVENT_NAMES, MODEL_VERSION, get_classifier


class ConvNextTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.classifier = get_classifier()
        output = BytesIO()
        Image.new('RGB', (300, 180), 'navy').save(output, format='PNG')
        cls.image = output.getvalue()

    def test_real_checkpoint_inference(self):
        result = self.classifier.classify(self.image)
        self.assertEqual(result['model_version'], MODEL_VERSION)
        self.assertEqual(len(self.classifier.classes), 8)
        probabilities = result['evidence'][1]['value']
        self.assertEqual(list(probabilities), list(self.classifier.classes))
        self.assertAlmostEqual(sum(probabilities.values()), 100, delta=0.05)
        self.assertGreaterEqual(result['confidence_score'], 0)
        self.assertLessEqual(result['confidence_score'], 100)
        self.assertEqual(self.classifier.model.classifier[2].out_features, 8)

    def test_every_output_uses_the_checkpoint_class_order(self):
        for index, label in enumerate(self.classifier.classes):
            logits = torch.full((1, 8), -10.0)
            logits[0, index] = 10.0
            with patch.object(self.classifier.model, 'forward', return_value=logits):
                result = self.classifier.classify(self.image)
            self.assertEqual(result['event_name'], EVENT_NAMES[label])
            self.assertEqual(result['evidence'][0]['value'], label)

    def test_uncertain_prediction_is_unclassified(self):
        with patch.object(self.classifier.model, 'forward', return_value=torch.zeros(1, 8)):
            result = self.classifier.classify(self.image)
        self.assertIsNone(result['event_name'])
        self.assertEqual(result['confidence_score'], 12.5)

    def test_invalid_image_is_rejected(self):
        with self.assertRaises(UnidentifiedImageError):
            self.classifier.classify(b'not an image')


if __name__ == '__main__':
    unittest.main()
