<?php

// Real native application fixture. No Http::fake or provider registration is used.
use App\Enums\PluginStatus;
use App\Enums\ServerState;
use App\Jobs\Schedule\RunTaskJob;
use App\Models\Allocation;
use App\Models\ApiKey;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Node;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Models\Subuser;
use App\Models\Task;
use App\Models\User;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Backups\DownloadLinkService;
use App\Services\Backups\InitiateBackupService;
use App\Services\Helpers\PluginService;
use App\Services\Servers\TransferServerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\FileTemplateActions;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\StartupBindingActions;
use ShookEagle\BetterPelicanSecrets\Node\NodeCapabilityService;
use ShookEagle\BetterPelicanSecrets\Plans\ApplicationStatusService;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Providers\BetterPelicanSecretsProvider;
use ShookEagle\BetterPelicanSecrets\Secrets\GrantService;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

require '/fixture/panel/vendor/autoload.php';
$app = require '/fixture/panel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!$app->environment('local') || base_path() !== '/fixture/panel'
    || config('database.connections.sqlite.database') !== '/fixture/panel/database/acceptance.sqlite') {
    throw new RuntimeException('Disposable native stack required.');
}
try {
    $action = $argv[1] ?? '';
    if ($action === 'import') {
        $id = app(PluginService::class)->downloadPluginFromFile(new UploadedFile('/workspace/dist/better-pelican-secrets-0.1.0.zip', 'release.zip', 'application/zip', null, true));
        if ($id !== 'better-pelican-secrets') {
            throw new RuntimeException('Wrong imported identity.');
        }
        echo json_encode(['imported' => $id]), PHP_EOL;
        exit;
    }
    if ($action === 'probe') {
        if (!$app->providerIsLoaded(BetterPelicanSecretsProvider::class)
            || Plugin::findOrFail('better-pelican-secrets')->status !== PluginStatus::Enabled) {
            throw new RuntimeException('Native provider not loaded.');
        }
        echo json_encode(['native_provider' => true, 'version' => config('app.version')]), PHP_EOL;
        exit;
    }
    if ($action === 'seed') {
        $root = User::factory()->create(['username' => 'native-root']);
        $root->assignRole(Role::getRootAdmin());
        $dev = User::factory()->create(['username' => 'native-developer', 'password' => Hash::make('Disposable-Native-123!')]);
        foreach (['root' => $root, 'developer' => $dev] as $label => $user) {
            $key = ApiKey::factory()->create(['user_id' => $user->id, 'key_type' => ApiKey::TYPE_ACCOUNT,
                'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_ACCOUNT)]);
            file_put_contents('/fixture/' . $label . '.token', $key->identifier . $key->token);
            chmod('/fixture/' . $label . '.token', 0600);
        }
        file_put_contents('/fixture/sftp.password', 'Disposable-Native-123!');
        chmod('/fixture/sftp.password', 0600);
        $nodes = [];
        $network = getenv('BPS_NATIVE_NETWORK');
        foreach (['wings', 'wings2', 'stock'] as $index => $host) {
            $node = Node::factory()->create(['name' => $host, 'fqdn' => $host, 'scheme' => 'https', 'daemon_listen' => 8443,
                'daemon_connect' => 8443, 'daemon_sftp' => 2022, 'daemon_base' => getenv('BPS_NATIVE_DATA' . ($index + 1)) ?: '/fixture/stock/data']);
            $nodes[] = $node->id;
            file_put_contents('/fixture/' . $host . '.token', $node->daemon_token);
            chmod('/fixture/' . $host . '.token', 0600);
            $data = getenv('BPS_NATIVE_DATA' . ($index + 1)) ?: '/fixture/stock/data';
            $runtime = getenv('BPS_NATIVE_RUNTIME' . ($index + 1)) ?: '/fixture/stock/runtime';
            $config = ['debug' => false, 'quiet' => true, 'uuid' => $node->uuid, 'token_id' => $node->daemon_token_id,
                'token' => $node->daemon_token, 'remote' => 'https://panel:8443',
                'api' => ['host' => '0.0.0.0', 'port' => 8443, 'ssl' => ['enabled' => true, 'cert' => '/fixture/tls/' . $host . '.crt', 'key' => '/fixture/tls/' . $host . '.key']],
                'system' => ['root_directory' => '/fixture/' . $host, 'data' => $data, 'secrets_runtime_directory' => $runtime,
                    'backup_directory' => '/fixture/' . $host . '/backups', 'archive_directory' => '/fixture/' . $host . '/archives',
                    'log_directory' => '/fixture/' . $host . '/logs', 'username' => 'bps-game', 'timezone' => 'UTC',
                    'user' => ['uid' => 1000, 'gid' => 1000, 'passwd' => ['enable' => false]], 'machine_id' => ['enable' => false],
                    'quotas' => ['enabled' => false], 'check_permissions_on_boot' => false, 'enable_log_rotate' => false,
                    'crash_detection' => ['enabled' => false], 'sftp' => ['bind_address' => '0.0.0.0', 'bind_port' => 2022]],
                'docker' => ['network' => ['name' => $network, 'network_mode' => 'none'], 'tmpfs_size' => 16],
                'remote_query' => ['timeout' => 10], 'ignore_panel_config_updates' => true];
            file_put_contents('/fixture/' . $host . '.json', json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            chmod('/fixture/' . $host . '.json', 0600);
        }
        $node = Node::findOrFail($nodes[0]);
        $server = Server::factory()->withNode($node)->create(['uuid' => getenv('BPS_NATIVE_SERVER'), 'name' => 'Native qualification',
            'owner_id' => $root->id, 'image' => '~' . getenv('BPS_NATIVE_IMAGE'), 'startup' => 'trap \'exit 0\' TERM INT; echo "startup={{BPS_TOKEN}}"; cat /home/container/config.json; while true; do sleep 1; done',
            'docker_labels' => ['better-pelican-secrets.dev' => 'true'], 'memory' => 128, 'disk' => 128, 'skip_scripts' => true]);
        Subuser::create(['user_id' => $dev->id, 'server_id' => $server->id,
            'permissions' => ['file.read', 'file.read-content', 'file.create', 'file.update', 'file.delete', 'file.archive', 'file.sftp',
                'startup.read', 'startup.update', 'control.console', 'control.start', 'control.stop', 'control.restart']]);
        $server->allocation->update(['ip' => '127.0.0.2', 'port' => (int) getenv('BPS_NATIVE_PORT1'), 'server_id' => $server->id]);
        $server->egg->update(['config_startup' => json_encode(['done' => ['startup='], 'strip_ansi' => false]),
            'config_stop' => '^SIGTERM', 'config_files' => '{}', 'config_logs' => '{}']);
        EggVariable::factory()->create(['egg_id' => $server->egg_id, 'env_variable' => 'BPS_TOKEN', 'default_value' => '',
            'user_viewable' => true, 'user_editable' => true, 'rules' => ['nullable', 'string']]);
        $directory = getenv('BPS_NATIVE_DATA1') . '/' . $server->uuid;
        mkdir($directory, 0755, true);
        foreach (['config.json' => '{"plugin":{"key":"legacy"}}', 'config.yaml' => 'key: legacy', '.env' => 'KEY=legacy', 'config.txt' => 'key=legacy'] as $path => $source) {
            file_put_contents($directory . '/' . $path, $source);
            chmod($directory . '/' . $path, 0644);
        }
        file_put_contents('/fixture/state.json', json_encode(['server' => $server->id, 'uuid' => $server->uuid, 'root' => $root->id, 'developer' => $dev->id, 'short' => $server->uuid_short, 'nodes' => $nodes]));
        echo json_encode(['seeded' => true]), PHP_EOL;
        exit;
    }
    $state = json_decode(file_get_contents('/fixture/state.json'), true, 16, JSON_THROW_ON_ERROR);
    $server = Server::findOrFail($state['server']);
    $root = User::findOrFail($state['root']);
    if ($action === 'setup') {
        $secret = app(SecretStore::class)->create($server, 'API_KEY', '', new SecretValue('fixture-secret'), $root);
        $actions = app(FileTemplateActions::class);
        foreach ([['config.json', 'json', 'json-string', '{"plugin":{"key":{{secret:API_KEY}}}}'],
            ['config.yaml', 'yaml', 'yaml-string', 'key: {{secret:API_KEY}}'],
            ['.env', 'dotenv', 'dotenv', 'KEY={{secret:API_KEY}}'],
            ['config.txt', 'text', 'raw', 'key={{secret:API_KEY}}']] as [$path, $format, $mode, $source]) {
            $draft = [...$actions->open($root, $server, $path), 'destination' => '/home/container/' . $path,
                'format' => $format, 'mode' => $mode, 'content' => $source,
                'format_confirmed' => true, 'readonly_confirmed' => true, 'migration_confirmed' => true];
            $prepared = $actions->prepareDraft($root, $server, $draft);
            $draft['references'] = array_map(fn ($entry) => ['secret_id' => $entry['secret_id'], 'mode' => $entry['mode']], $prepared['file']->manifest);
            $actions->saveDraft($root, $server, $draft);
        }
        app(StartupBindingActions::class)->bind($root, $server, 'BPS_TOKEN', $secret->secretId);
        $state['secret'] = $secret->secretId;
        file_put_contents('/fixture/state.json', json_encode($state));
        echo json_encode(['configured' => true]), PHP_EOL;
    } elseif ($action === 'stock-check') {
        $stock = Node::findOrFail($state['nodes'][2]);
        if (app(NodeCapabilityService::class)->compatible($stock, true)) {
            throw new RuntimeException('Stock node falsely advertised protocol.');
        }
        $secret = app(SecretStore::class)->create($server, 'STOCK_REJECT', '', new SecretValue('stock-test-value'), $root);
        $other = Server::factory()->withNode($stock)->create(['owner_id' => $root->id]);
        EggVariable::factory()->create(['egg_id' => $other->egg_id, 'env_variable' => 'BPS_TOKEN', 'default_value' => '', 'rules' => ['nullable', 'string']]);
        // Cross-server reference is intentionally not used: create its own value.
        $owned = app(SecretStore::class)->create($other, 'STOCK_KEY', '', new SecretValue('stock-test-value'), $root);
        $rejected = false;
        try {
            app(BindingService::class)->bindStartup($root, $other, 'BPS_TOKEN', $owned->secretId);
        } catch (DomainException $error) {
            $rejected = $error->getMessage() === 'node_incompatible';
        }
        if (!$rejected) {
            throw new RuntimeException('Stock activation was not rejected.');
        }
        $other->delete();
        app(BindingService::class)->deleteSecret($root, $server, $secret->secretId);
        echo json_encode(['stock_rejected' => true]), PHP_EOL;
    } elseif ($action === 'metadata') {
        echo json_encode(['uuid' => $server->uuid, 'short' => $server->uuid_short, 'secret' => $state['secret'], 'node' => $server->node->name]), PHP_EOL;
    } elseif ($action === 'grant-reveal' || $action === 'revoke-reveal') {
        $recipient = User::findOrFail($state['developer']);
        $scope = new SecretScope($server->id, $state['secret']);
        $service = app(GrantService::class);
        if ($action === 'grant-reveal') {
            $service->grant($root, $recipient, $scope, [SecretOperation::ListSecrets, SecretOperation::Reveal]);
        } else {
            $service->revoke($root, $recipient, $scope, [SecretOperation::Reveal]);
        }
        echo json_encode(['changed' => true]), PHP_EOL;
    } elseif ($action === 'schedule-start') {
        $schedule = Schedule::factory()->create(['server_id' => $server->id, 'is_active' => true, 'is_processing' => true,
            'cron_minute' => '*', 'cron_hour' => '*', 'cron_day_of_month' => '*', 'cron_month' => '*', 'cron_day_of_week' => '*']);
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'sequence_id' => 1, 'action' => 'power',
            'payload' => 'start', 'time_offset' => 0, 'is_queued' => true]);
        app()->call([new RunTaskJob($task), 'handle']);
        echo json_encode(['scheduled' => true]), PHP_EOL;
    } elseif ($action === 'backup') {
        $server->update(['backup_limit' => 3]);
        BackupHost::factory()->create(['schema' => 'wings']);
        $deleted = app(SecretStore::class)->create($server, 'RESTORE_DELETE_ME', '', new SecretValue('unbound-fixture'), $root);
        $backup = app(InitiateBackupService::class)->handle($server, 'Native qualification');
        app(BindingService::class)->deleteSecret($root, $server, $deleted->secretId);
        $state['backup'] = $backup->uuid;
        file_put_contents('/fixture/state.json', json_encode($state));
        echo json_encode(['backup' => $backup->uuid]), PHP_EOL;
    } elseif ($action === 'backup-status') {
        $backup = Backup::where('uuid', $state['backup'])->firstOrFail();
        echo json_encode(['complete' => $backup->completed_at !== null, 'success' => $backup->is_successful]), PHP_EOL;
    } elseif ($action === 'backup-url') {
        $backup = Backup::where('uuid', $state['backup'])->firstOrFail();
        echo json_encode(['url' => app(DownloadLinkService::class)->handle($backup, $root)]), PHP_EOL;
    } elseif ($action === 'restore') {
        $backup = Backup::where('uuid', $state['backup'])->firstOrFail();
        $server->update(['status' => ServerState::RestoringBackup]);
        app(DaemonBackupRepository::class)->setServer($server)->restore($backup);
        echo json_encode(['restoring' => true]), PHP_EOL;
    } elseif ($action === 'restore-status') {
        echo json_encode(['complete' => $server->status === null,
            'deleted_absent' => !Secret::where('server_id', $server->id)->where('name', 'RESTORE_DELETE_ME')->exists()]), PHP_EOL;
    } elseif ($action === 'invalidate-egg') {
        $state['original_egg'] = $server->egg_id;
        file_put_contents('/fixture/state.json', json_encode($state));
        $server->update(['egg_id' => Egg::factory()->create()->id]);
        echo json_encode(['invalidated' => true]), PHP_EOL;
    } elseif ($action === 'restore-egg') {
        $server->update(['egg_id' => $state['original_egg']]);
        echo json_encode(['restored' => true]), PHP_EOL;
    } elseif ($action === 'invalidate-image') {
        $before = app(PlanService::class)->current($server)->revision;
        $server->update(['image' => '~' . getenv('BPS_NATIVE_IMAGE') . '-alias']);
        echo json_encode(['changed' => $before !== app(PlanService::class)->current($server)->revision]), PHP_EOL;
    } elseif ($action === 'transfer') {
        $destination = Node::findOrFail($state['nodes'][1]);
        $allocation = Allocation::factory()->create(['node_id' => $destination->id, 'ip' => '127.0.0.2', 'port' => (int) getenv('BPS_NATIVE_PORT2')]);
        app(TransferServerService::class)->handle($server, $destination->id, $allocation->id);
        echo json_encode(['transferring' => true]), PHP_EOL;
    } elseif ($action === 'transfer-status') {
        $transfer = ServerTransfer::where('server_id', $server->id)->orderByDesc('id')->firstOrFail();
        echo json_encode(['success' => $transfer->successful === true, 'node' => $server->node_id === $state['nodes'][1]]), PHP_EOL;
    } elseif ($action === 'delete') {
        app(DaemonServerRepository::class)->setServer($server)->delete();
        $server->delete();
        echo json_encode(['deleted' => true]), PHP_EOL;
    } elseif ($action === 'status') {
        $status = app(ApplicationStatusService::class)->forServer($server);
        echo json_encode($status), PHP_EOL;
    } elseif ($action === 'rotate') {
        $secret = Secret::where('server_id', $server->id)->firstOrFail();
        app(SecretStore::class)->replace($secret->id, new SecretValue('rotated-fixture'), $root);
        echo json_encode(['rotated' => true, 'desired' => app(PlanService::class)->current($server)->revision]), PHP_EOL;
    } else {
        throw new RuntimeException('Unknown fixture action.');
    }
} catch (Throwable $error) {
    // No exception body/trace may include a synthetic credential.
    fwrite(STDERR, 'Native Panel fixture failed: ' . get_class($error) . ' ' . basename($error->getFile()) . ':' . $error->getLine() . PHP_EOL);
    exit(1);
}
