"""Native power lifecycle, orphan inspection, retirement and safe outage recovery."""
import test_secret_start as native


class LifecycleTests(native.SecretStartTests):
    go_test_name = "TestSecretLifecycleRecoveryLive"
