import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location('activate', pathlib.Path(__file__).parents[1] / 'activate_create_worker.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class ActivationTest(unittest.TestCase):
    def test_timeout_never_kills_starts_or_switches_a_worker(self):
        calls = []
        def run(args):
            calls.append(args)
            return 'ActiveState=deactivating\nMainPID=42' if args[0] == 'systemctl' else ''
        with self.assertRaisesRegex(m.Blocked, 'timed out'):
            m.stop_and_wait(run, timeout=0)
        self.assertEqual(2, len(calls))
        self.assertEqual(['sudo', 'systemctl', 'stop', '--no-block', 'wyv-create-worker'], calls[0])

    def test_only_inactive_with_no_pid_is_accepted(self):
        states = iter(['ActiveState=inactive\nMainPID=42', 'ActiveState=inactive\nMainPID=0'])
        slept = []
        def run(args):
            return next(states) if args[0] == 'systemctl' else ''
        m.stop_and_wait(run, timeout=10, clock=lambda: 0, sleep=slept.append)
        self.assertEqual([2], slept)

    def test_failed_service_or_orphaned_sandbox_blocks_activation(self):
        with self.assertRaisesRegex(m.Blocked, 'failed while stopping'):
            m.stop_and_wait(lambda args: 'ActiveState=failed\nMainPID=0', timeout=0)
        with self.assertRaisesRegex(m.Blocked, 'sandbox is still running'):
            m.no_sandboxes(lambda args: 'container123')


if __name__ == '__main__':
    unittest.main()
