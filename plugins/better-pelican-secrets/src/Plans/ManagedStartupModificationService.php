<?php

namespace ShookEagle\BetterPelicanSecrets\Plans;

use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Models\User;
use App\Services\Servers\StartupModificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use ShookEagle\BetterPelicanSecrets\Plans\Models\StartupBinding;

/** Pinned native startup flow, excluding managed values from ordinary validation/writes. */
final class ManagedStartupModificationService extends StartupModificationService
{
    public function handle(Server $server, #[\SensitiveParameter] array $data): Server
    {
        return DB::transaction(function () use ($server, $data) {
            $managed = StartupBinding::query()->where('server_id', $server->id)->pluck('env')->all();
            foreach ($data['environment'] ?? [] as $env => $value) {
                app(ManagedVariableGuard::class)->assertWritable($server, (string) $env);
            }
            if (!empty($data['environment'])) {
                $egg = $this->isUserLevel(User::USER_LEVEL_ADMIN) ? ($data['egg_id'] ?? $server->egg_id) : $server->egg_id;
                $query = EggVariable::query()->where('egg_id', $egg)->whereNotIn('env_variable', $managed);
                if (!$this->isUserLevel(User::USER_LEVEL_ADMIN)) {
                    $query->where('user_editable', true)->where('user_viewable', true);
                }
                $variables = $query->get();
                $input = $rules = $attributes = [];
                foreach ($variables as $variable) {
                    $key = 'environment.' . $variable->env_variable;
                    $input['environment'][$variable->env_variable] = $data['environment'][$variable->env_variable] ?? $variable->default_value;
                    $rules[$key] = $variable->rules;
                    $attributes[$key] = trans('validation.internal.variable_value', ['env' => $variable->name]);
                }
                Validator::make($input, $rules, [], $attributes)->validate();
                foreach ($variables as $variable) {
                    ServerVariable::query()->updateOrCreate(['server_id' => $server->id, 'variable_id' => $variable->id],
                        ['variable_value' => $data['environment'][$variable->env_variable] ?? '']);
                }
            }
            if ($this->isUserLevel(User::USER_LEVEL_ADMIN)) {
                $this->updateAdministrativeSettings($data, $server);
            }

            return $server->fresh() ?? $server;
        });
    }
}
