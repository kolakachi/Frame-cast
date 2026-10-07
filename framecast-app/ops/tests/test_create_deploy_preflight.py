import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location('preflight', pathlib.Path(__file__).parents[1] / 'create_deploy_preflight.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class PreflightTest(unittest.TestCase):
    def clear(self):
        return dict(controls_enabled=True, draining=True, durable_planning=True, planning=[], builds={},
                    unconfirmed_workers=0, unresolved_attempts=0, pending_media=0)

    def test_queued_work_may_survive_but_running_and_uncertain_work_blocks(self):
        status = self.clear()
        status['builds'] = {'queued': 3, 'succeeded': 2}
        self.assertEqual([], m.journal_blockers(status))
        for group, state in [('planning', 'running'), ('planning', 'needs_attention'),
                             ('builds', 'running'), ('builds', 'cancel_requested'), ('builds', 'needs_attention')]:
            status = self.clear()
            status[group] = {state: 1}
            self.assertIn(group + '.' + state, m.journal_blockers(status))
        for key in ('pending_media', 'unconfirmed_workers', 'unresolved_attempts'):
            status = self.clear()
            status[key] = 1
            self.assertIn(key, m.journal_blockers(status))

    def test_disabled_missing_and_malformed_inventory_fail_closed(self):
        for key in self.clear():
            status = self.clear()
            del status[key]
            self.assertTrue(m.journal_blockers(status))
        for value in ('0', None, False, -1):
            status = self.clear()
            status['pending_media'] = value
            self.assertTrue(m.journal_blockers(status))
        status = self.clear()
        status['builds'] = {'running': '0'}
        self.assertTrue(m.journal_blockers(status))
        self.assertTrue(m.journal_blockers(None))

    def test_container_layer_and_similarly_named_mount_do_not_protect_storage(self):
        root = '/var/www/html/storage/app/private'
        self.assertFalse(m.persistent_storage([], root))
        for kind, dest, rw in [('tmpfs', '/var/www/html/storage', True),
                                ('volume', '/var/www/html/storage/app2', True),
                                ('volume', '/var/www/html/storage', False)]:
            self.assertFalse(m.persistent_storage([dict(Type=kind, Destination=dest, RW=rw)], root))
        for kind in ('volume', 'bind'):
            self.assertTrue(m.persistent_storage([dict(Type=kind, Destination='/var/www/html/storage/app', RW=True)], root))


if __name__ == '__main__':
    unittest.main()
