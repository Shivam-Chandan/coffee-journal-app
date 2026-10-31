"""Offline regression fixtures for the deployment path boundary."""

import importlib.util
import pathlib
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("artifact_inspector", pathlib.Path(__file__).with_name("inspect-artifact.py"))
inspector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inspector)


class ArtifactInspectionTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = pathlib.Path(self.scratch.name)
        (self.root / "vendor").mkdir()
        (self.root / "vendor" / "autoload.php").touch()

    def test_runtime_tree_and_internal_link_are_allowed(self):
        (self.root / "vendor" / "autoload-link.php").symlink_to("autoload.php")
        self.assertEqual([], inspector.inspect(self.root))

    def test_credential_and_unexpected_roots_are_rejected(self):
        for name in ["salt.txt", "ssh-keys-github", ".env", "debug.log"]:
            (self.root / name).touch()
        self.assertEqual([".env", "debug.log", "salt.txt", "ssh-keys-github"], inspector.inspect(self.root))

    def test_nested_credentials_and_repository_metadata_are_rejected(self):
        (self.root / "vendor" / "dummy.key").touch()
        (self.root / "vendor" / ".git").mkdir()
        self.assertEqual(["vendor/.git", "vendor/dummy.key"], inspector.inspect(self.root))

    def test_escaping_and_dangling_links_are_rejected(self):
        (self.root / "vendor" / "outside").symlink_to("../../outside-this-artifact")
        self.assertEqual(["vendor/outside"], inspector.inspect(self.root))


if __name__ == "__main__":
    unittest.main()
