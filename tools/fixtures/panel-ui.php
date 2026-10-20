<?php

use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use ShookEagle\BetterPelicanSecrets\Secrets\GrantService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation as Op;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

require '/workspace/.work/panel/vendor/autoload.php';
$app = require '/workspace/.work/panel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!$app->environment('local') || config('database.connections.sqlite.database') !== '/workspace/.work/panel/database/bps-ui.sqlite') {
    throw new RuntimeException('Only the disposable UI database is permitted.');
}
try {
Artisan::call('migrate', ['--force' => true]);
$root = User::firstWhere('username', 'bps-root');
if (!$root) {
    $root = User::factory()->create(['username' => 'bps-root', 'email' => 'bps-root@example.test', 'password' => Hash::make('Disposable-Panel-123!')]);
    $root->assignRole(Role::getRootAdmin());
    $manager = User::factory()->create(['username' => 'bps-manager', 'email' => 'bps-manager@example.test', 'password' => Hash::make('Disposable-Panel-123!')]);
    $dev = User::factory()->create(['username' => 'bps-developer', 'email' => 'bps-developer@example.test', 'password' => Hash::make('Disposable-Panel-123!')]);
    $server = Server::factory()->create(['name' => 'CS2 development', 'owner_id' => $manager->id, 'status' => null]);
    Subuser::create(['server_id' => $server->id, 'user_id' => $dev->id, 'permissions' => ['file.read', 'file.read-content', 'file.update', 'startup.read', 'startup.update']]);
    app(SecretStore::class)->create($server, 'RCON_PASSWORD', 'Remote console password for CS2.', new SecretValue('visual-fixture-value'), $root);
    app(SecretStore::class)->create($server, 'PLUGIN_API_KEY', 'Credential used by a plugin configuration.', SecretValue::generate(), $root);
    app(GrantService::class)->grant($root, $manager, new SecretScope($server->id), [Op::ListSecrets, Op::Create, Op::Update, Op::Bind, Op::Delete, Op::ManageAccess]);
}
$server = Server::firstWhere('name', 'CS2 development');
echo json_encode(['server' => $server->uuid_short, 'users' => ['bps-root', 'bps-manager', 'bps-developer'], 'password' => 'Disposable-Panel-123!'], JSON_THROW_ON_ERROR), PHP_EOL;

} catch (Throwable) {
    fwrite(STDERR, "Disposable UI fixture setup failed.\n");
    exit(1);
}
